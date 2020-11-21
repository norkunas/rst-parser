<?php

declare(strict_types=1);

namespace Doctrine\RST\Nodes;

class SectionBeginNode extends Node
{
    /** @var TitleNode */
    private $titleNode;

    public function __construct(TitleNode $titleNode)
    {
        parent::__construct();

        $this->titleNode = $titleNode;
    }

    public function getTitleNode(): TitleNode
    {
        return $this->titleNode;
    }
}
