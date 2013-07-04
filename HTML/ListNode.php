<?php

namespace Gregwar\RST\HTML;

use Gregwar\RST\Nodes\ListNode as Base;

class ListNode extends Base
{
    protected $lines = array();

    public function addLine($text, $ordered, $depth)
    {
        $this->lines[] = array($text, $ordered, $depth);
    }

    public function render()
    {
        $depth = -1;
        $value = '';
        $stack = array();

        foreach ($this->lines as $line) {
            list($text, $ordered, $newDepth) = $line;
            $keyword = $ordered ? 'ol' : 'ul';

            if ($depth < $newDepth) {
                $value .= '<' . $keyword . '>';
                $stack[] = array($newDepth, '</' . $keyword . '>');
                $depth = $newDepth;
            }

            while ($depth > $newDepth) {
                $top = $stack[count($stack)-1];

                if ($top[0] > $newDepth) {
                    $value .= $top[1];
                    array_pop($stack);
                    $top = $stack[count($stack)-1];
                    $depth = $top[0];
                }
            }

            $value .= '<li>'.$text.'</li>';
        }

        while ($stack) {
            list($d, $closing) = array_pop($stack);
            $value .= $closing; 
        }

        return '<p>'.$value.'</p>';
    }
}