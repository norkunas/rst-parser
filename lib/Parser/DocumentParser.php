<?php

declare(strict_types=1);

namespace Doctrine\RST\Parser;

use Doctrine\Common\EventManager;
use Doctrine\RST\Directives\Directive;
use Doctrine\RST\Environment;
use Doctrine\RST\Event\PostParseDocumentEvent;
use Doctrine\RST\Event\PreParseDocumentEvent;
use Doctrine\RST\FileIncluder;
use Doctrine\RST\NodeFactory\NodeFactory;
use Doctrine\RST\Nodes\DocumentNode;
use Doctrine\RST\Nodes\ListNode;
use Doctrine\RST\Nodes\Node;
use Doctrine\RST\Nodes\TableNode;
use Doctrine\RST\Parser;
use Doctrine\RST\Parser\Directive as ParserDirective;
use function explode;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

class DocumentParser
{
    /** @var Parser */
    private $parser;

    /** @var Environment */
    private $environment;

    /** @var NodeFactory */
    private $nodeFactory;

    /** @var EventManager */
    private $eventManager;

    /** @var Directive[] */
    private $directives = [];

    /** @var bool */
    private $includeAllowed = true;

    /** @var string */
    private $includeRoot = '';

    /** @var DocumentNode */
    private $document;

    /** @var false|string|null */
    private $specialLetter;

    /** @var ParserDirective|null */
    private $directive;

    /** @var LineDataParser */
    private $lineDataParser;

    /** @var LineChecker */
    private $lineChecker;

    /** @var TableParser */
    private $tableParser;

    /** @var Buffer */
    private $buffer;

    /** @var Node|null */
    private $nodeBuffer;

    /** @var bool */
    private $isCode = false;

    /** @var Lines */
    private $lines;

    /** @var string */
    private $state;

    /** @var ListLine|null */
    private $listLine;

    /** @var bool */
    private $listFlow = false;

    /**
     * @param Directive[] $directives
     */
    public function __construct(
        Parser $parser,
        Environment $environment,
        NodeFactory $nodeFactory,
        EventManager $eventManager,
        array $directives,
        bool $includeAllowed,
        string $includeRoot
    ) {
        $this->parser         = $parser;
        $this->environment    = $environment;
        $this->nodeFactory    = $nodeFactory;
        $this->eventManager   = $eventManager;
        $this->directives     = $directives;
        $this->includeAllowed = $includeAllowed;
        $this->includeRoot    = $includeRoot;
        $this->lineDataParser = new LineDataParser($this->parser);
        $this->lineChecker    = new LineChecker($this->lineDataParser);
        $this->tableParser    = new TableParser();
        $this->buffer         = new Buffer();
    }

    public function getDocument() : DocumentNode
    {
        return $this->document;
    }

    public function parse(string $contents) : DocumentNode
    {
        $preParseDocumentEvent = new PreParseDocumentEvent($this->parser, $contents);

        $this->eventManager->dispatchEvent(
            PreParseDocumentEvent::PRE_PARSE_DOCUMENT,
            $preParseDocumentEvent
        );

        $this->document = $this->nodeFactory->createDocumentNode($this->environment);

        $this->init();

        $this->parseLines(trim($preParseDocumentEvent->getContents()));

        foreach ($this->directives as $name => $directive) {
            $directive->finalize($this->document);
        }

        $this->eventManager->dispatchEvent(
            PostParseDocumentEvent::POST_PARSE_DOCUMENT,
            new PostParseDocumentEvent($this->document)
        );

        return $this->document;
    }

    private function init() : void
    {
        $this->specialLetter = false;
        $this->buffer        = new Buffer();
        $this->nodeBuffer    = null;
    }

    private function setState(string $state) : void
    {
        $this->state = $state;
    }

    private function prepareDocument(string $document) : string
    {
        $document = str_replace("\r\n", "\n", $document);
        $document = sprintf("\n%s\n", $document);

        $document = (new FileIncluder(
            $this->environment,
            $this->includeAllowed,
            $this->includeRoot
        ))->includeFiles($document);

        // Removing UTF-8 BOM
        $document = str_replace("\xef\xbb\xbf", '', $document);

        return $document;
    }

    private function createLines(string $document) : Lines
    {
        return new Lines(explode("\n", $document));
    }

    private function parseLines(string $document) : void
    {
        $document = $this->prepareDocument($document);

        $this->lines = $this->createLines($document);
        $this->setState(State::BEGIN);

        foreach ($this->lines as $line) {
            while (true) {
                if ($this->parseLine($line)) {
                    break;
                }
            }
        }

        // DocumentNode is flushed twice to trigger the directives
        $this->flush();
        $this->flush();
    }

    private function parseLine(string $line) : bool
    {
        switch ($this->state) {
            case State::BEGIN:
                if (trim($line) !== '') {
                    if ($this->lineChecker->isListLine($line, $this->isCode)) {
                        $this->setState(State::LIST);

                        /** @var ListNode $listNode */
                        $listNode = $this->nodeFactory->createListNode();

                        $this->nodeBuffer = $listNode;

                        $this->listLine = null;
                        $this->listFlow = true;

                        return false;
                    } elseif ($this->lineChecker->isBlockLine($line)) {
                        if ($this->isCode) {
                            $this->setState(State::CODE);
                        } else {
                            $this->setState(State::BLOCK);
                        }
                        return false;
                    } elseif ($this->lineChecker->isDirective($line)) {
                        $this->setState(State::DIRECTIVE);
                        $this->buffer = new Buffer();
                        $this->flush();
                        $this->initDirective($line);
                    } elseif ($this->parseLink($line)) {
                        return true;
                    } elseif ($this->lineChecker->isDefinitionList($this->lines->getNextLine())) {
                        $this->setState(State::DEFINITION_LIST);
                        $this->buffer->push($line);

                        return true;
                    } else {
                        $tableParts = $this->tableParser->parseTableLine($line);

                        if ($tableParts === null) {
                            $this->setState(State::NORMAL);

                            return false;
                        }

                        $this->setState(State::TABLE);

                        $tableNode = $this->nodeFactory->createTableNode(
                            $tableParts,
                            $this->tableParser->guessTableType($line),
                            $this->lineChecker
                        );

                        $this->nodeBuffer = $tableNode;
                    }
                }
                break;

            case State::LIST:
                if (! $this->parseListLine($line)) {
                    $this->flush();
                    $this->setState(State::BEGIN);

                    return false;
                }
                break;

            case State::DEFINITION_LIST:
                if ($this->lineChecker->isDefinitionListEnded($line, $this->lines->getNextLine())) {
                    $this->flush();
                    $this->setState(State::BEGIN);

                    return false;
                }

                $this->buffer->push($line);

                break;

            case State::TABLE:
                if (trim($line) === '') {
                    $this->flush();
                    $this->setState(State::BEGIN);
                } else {
                    $parts = $this->tableParser->parseTableLine($line);

                    if ($this->nodeBuffer instanceof TableNode && ! $this->nodeBuffer->push($parts, $line)) {
                        $this->flush();

                        $this->setState(State::BEGIN);

                        return false;
                    }
                }

                break;

            case State::NORMAL:
                if (trim($line) !== '') {
                    $specialLetter = $this->lineChecker->isSpecialLine($line);

                    if ($specialLetter !== null) {
                        $this->specialLetter = $specialLetter;

                        $lastLine = $this->buffer->pop();

                        if ($lastLine !== null) {
                            $this->buffer = new Buffer([$lastLine]);
                            $this->setState(State::TITLE);
                        } else {
                            $this->buffer->push($line);
                            $this->setState(State::SEPARATOR);
                        }
                        $this->flush();
                        $this->setState(State::BEGIN);
                    } elseif ($this->lineChecker->isDirective($line)) {
                        $this->flush();
                        $this->setState(State::BEGIN);

                        return false;
                    } elseif ($this->lineChecker->isComment($line)) {
                        $this->flush();
                        $this->setState(State::COMMENT);
                    } else {
                        $this->buffer->push($line);
                    }
                } else {
                    $this->flush();
                    $this->setState(State::BEGIN);
                }
                break;

            case State::COMMENT:
                $isComment = false;

                if (! $this->lineChecker->isComment($line) && (trim($line) === '' || $line[0] !== ' ')) {
                    $this->setState(State::BEGIN);
                    return false;
                }
                break;

            case State::BLOCK:
            case State::CODE:
                if (! $this->lineChecker->isBlockLine($line)) {
                    $this->flush();
                    $this->setState(State::BEGIN);
                    return false;
                } else {
                    $this->buffer->push($line);
                }
                break;

            case State::DIRECTIVE:
                if (! $this->isDirectiveOption($line)) {
                    if (! $this->lineChecker->isDirective($line)) {
                        $directive    = $this->getCurrentDirective();
                        $this->isCode = $directive !== null ? $directive->wantCode() : false;
                        $this->setState(State::BEGIN);

                        return false;
                    }

                    $this->flush();
                    $this->initDirective($line);
                }
                break;

            default:
                $this->environment->getErrorManager()->error('Parser ended in an unexcepted state');
        }

        return true;
    }

    private function flush() : void
    {
        $node = null;

        $this->isCode = false;

        if ($this->hasBuffer()) {
            switch ($this->state) {
                case State::TITLE:
                    $data = $this->buffer->getLinesString();

                    $level = $this->environment->getLevel((string) $this->specialLetter);

                    $token = $this->environment->createTitle($level);

                    $node = $this->nodeFactory->createTitleNode(
                        $this->parser->createSpanNode($data),
                        $level,
                        $token
                    );

                    break;

                case State::SEPARATOR:
                    $level = $this->environment->getLevel((string) $this->specialLetter);

                    $node = $this->nodeFactory->createSeparatorNode($level);

                    break;

                case State::CODE:
                    /** @var string[] $buffer */
                    $buffer = $this->buffer->getLines();

                    $node = $this->nodeFactory->createCodeNode($buffer);

                    break;

                case State::BLOCK:
                    /** @var string[] $lines */
                    $lines = $this->buffer->getLines();

                    $blockNode = $this->nodeFactory->createBlockNode($lines);

                    $document = $this->parser->getSubParser()->parseLocal($blockNode->getValue());

                    $node = $this->nodeFactory->createQuoteNode($document);

                    break;

                case State::LIST:
                    $this->parseListLine(null, true);

                    /** @var ListNode $node */
                    $node = $this->nodeBuffer;

                    break;

                case State::DEFINITION_LIST:
                    $definitionList = $this->lineDataParser->parseDefinitionList(
                        $this->buffer->getLines()
                    );

                    $node = $this->nodeFactory->createDefinitionListNode($definitionList);

                    break;

                case State::TABLE:
                    /** @var TableNode $node */
                    $node = $this->nodeBuffer;

                    $node->finalize($this->parser);

                    break;

                case State::NORMAL:
                    $this->isCode = $this->prepareCode();

                    $buffer = $this->buffer->getLinesString();

                    $node = $this->nodeFactory->createParagraphNode($this->parser->createSpanNode($buffer));

                    break;
            }
        }

        if ($this->directive !== null) {
            $currentDirective = $this->getCurrentDirective();

            if ($currentDirective !== null) {
                $currentDirective->process(
                    $this->parser,
                    $node,
                    $this->directive->getVariable(),
                    $this->directive->getData(),
                    $this->directive->getOptions()
                );
            }

            $node = null;
        }

        $this->directive = null;

        if ($node !== null) {
            $this->document->addNode($node);
        }

        $this->init();
    }

    private function hasBuffer() : bool
    {
        return ! $this->buffer->isEmpty() || $this->nodeBuffer !== null;
    }

    private function getCurrentDirective() : ?Directive
    {
        if ($this->directive === null) {
            return null;
        }

        $name = $this->directive->getName();

        if (! isset($this->directives[$name])) {
            $message = 'Unknown directive: ' . $name;

            $this->environment->getErrorManager()->error($message);

            return null;
        }

        return $this->directives[$name];
    }

    private function isDirectiveOption(string $line) : bool
    {
        if ($this->directive === null) {
            return false;
        }

        $directiveOption = $this->lineDataParser->parseDirectiveOption($line);

        if ($directiveOption === null) {
            return false;
        }

        $this->directive->setOption($directiveOption->getName(), $directiveOption->getValue());

        return true;
    }

    private function initDirective(string $line) : bool
    {
        $parserDirective = $this->lineDataParser->parseDirective($line);

        if ($parserDirective !== null) {
            $this->directive = $parserDirective;

            return true;
        }

        return false;
    }

    private function prepareCode() : bool
    {
        $lastLine = $this->buffer->getLastLine();

        if ($lastLine === null) {
            return false;
        }

        $trimmedLastLine = trim($lastLine);

        if (strlen($trimmedLastLine) >= 2) {
            if (substr($trimmedLastLine, -2) === '::') {
                if (trim($trimmedLastLine) === '::') {
                    $this->buffer->pop();
                } else {
                    $this->buffer->set($this->buffer->count() - 1, substr($trimmedLastLine, 0, -1));
                }

                return true;
            }
        }

        return false;
    }

    private function parseLink(string $line) : bool
    {
        $link = $this->lineDataParser->parseLink($line);

        if ($link === null) {
            return false;
        }

        if ($link->getType() === Link::TYPE_ANCHOR) {
            $anchorNode = $this->nodeFactory
                ->createAnchorNode($link->getName());

            $this->document->addNode($anchorNode);
        }

        $this->environment->setLink($link->getName(), $link->getUrl());

        return true;
    }

    private function parseListLine(?string $line, bool $flush = false) : bool
    {
        if ($line !== null && trim($line) !== '') {
            $listLine = $this->lineDataParser->parseListLine($line);

            if ($listLine !== null) {
                if ($this->listLine instanceof ListLine) {
                    $this->listLine->setText($this->parser->createSpanNode($this->listLine->getText()));

                    /** @var ListNode $listNode */
                    $listNode = $this->nodeBuffer;

                    $listNode->addLine($this->listLine->toArray());
                }
                $this->listLine = $listLine;
            } else {
                if ($this->listLine instanceof ListLine && ($this->listFlow || $line[0] === ' ')) {
                    $this->listLine->addText($line);
                } else {
                    $flush = true;
                }
            }
            $this->listFlow = true;
        } else {
            $this->listFlow = false;
        }

        if ($flush) {
            if ($this->listLine instanceof ListLine) {
                $this->listLine->setText($this->parser->createSpanNode($this->listLine->getText()));

                /** @var ListNode $listNode */
                $listNode = $this->nodeBuffer;

                $listNode->addLine($this->listLine->toArray());

                $this->listLine = null;
            }

            return false;
        }

        return true;
    }
}
