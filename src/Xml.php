<?php
/**
 * Created by PhpStorm
 * Date 2023/3/28 14:13
 */

namespace Chance\SwowDebug;

use Sabre\Xml\Writer;

class Xml
{
    public static function generate($rootAttributes = [], $content = [], $rootName = 'response'): string
    {
        $w = new Writer();
        $w->openMemory();
        $w->startDocument('1.0','iso-8859-1');
        $w->startElement($rootName);
        foreach ($rootAttributes as $name => $value) {
            $w->writeAttribute($name, (string)$value);
        }
        $w->write($content);
        $w->endElement();
        $x = htmlspecialchars_decode($w->outputMemory());
        return strlen($x) . "\x00" . $x . "\x00";
    }
}