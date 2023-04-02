<?php
/**
 * Created by PhpStorm
 * Date 2023/3/28 16:50
 */

namespace Chance\SwowDebugger;

use Swow\Coroutine;

class Dbgp
{
    protected static string $xmlns = 'urn:debugger_protocol_v1';
    const NAME = 'name';
    const ATTRIBUTES = 'attributes';
    const VALUE = 'value';

    public static function init(string $ideKey): string
    {
        return Xml::generate([
            'appid' => Coroutine::getCurrent()->getId(),
            'idekey' => $ideKey,
            'language' => 'PHP',
            'protocol_version' => '1.0',
            'fileuri' => '',
            'xmlns' => self::$xmlns,
        ], [
            [
                self::NAME => 'engine',
                self::ATTRIBUTES => ['version' => '1.0.0'],
                self::VALUE => '<![CDATA[SDB]]>',
            ],
            'author' => '<![CDATA[Chance]]>',
        ], rootName: 'init');
    }

    public static function feature_set($command, $i, $n, $v): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
            'feature' => $n,
            'success' => 1,
        ]);
    }

    public static function stdout($command, $i, $c): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
            'success' => 1,
        ]);
    }

    public static function status($command, $i): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
            'status' => 'starting',
            'reason' => 'ok',
        ]);
    }

    public static function step_into($command, $i): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
            'status' => 'break',
            'reason' => 'ok',
        ]);
    }

    public static function eval($command, $i, $data): string
    {
        $code = base64_decode($data);
        if (str_contains($code, 'PHP_IDE_CONFIG')) {
            [$type, $value] = ['bool', 0];
        } elseif (str_contains($code, 'isset') && str_contains($code, 'SERVER_NAME')) {
            [$type, $value] = ['bool', 1];
        } elseif (str_contains($code, 'string') && str_contains($code, 'SERVER_NAME')) {
            [$type, $value] = ['string', base64_encode('127.0.0.1')];
        } elseif (str_contains($code, 'string') && str_contains($code, 'SERVER_PORT')) {
            [$type, $value] = ['string', base64_encode('80')];
        } elseif (str_contains($code, 'string') && str_contains($code, 'REQUEST_URI')) {
            [$type, $value] = ['string', base64_encode('')];
        } else {
            return '';
        }
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
        ], [
            self::NAME => 'property',
            self::ATTRIBUTES => $type === 'bool' ? [
                'type' => $type
            ] : [
                'type' => $type,
                'size' => (string)strlen(base64_decode($value)),
                'encoding' => 'base64',
            ],
            self::VALUE => "<![CDATA[$value]]>",
        ]);
    }

    public static function breakpoint_set($command, $i, $t, $f, $n): string
    {
        if (str_starts_with($f, 'file://')) {
            $f = substr($f, 7);
        }
        $point = "$f:$n";
        $id = Debugger::setBreakPoint($point);
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
            'id' => $id,
        ]);
    }

    public static function breakpoint_remove($command, $i, $d): void
    {
        Debugger::removeBreakPoint($d);
    }

    public static function stack_get($command, $i)
    {
        $level = 0;
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
        ], array_map(function ($trace) use (&$level) {
            return [
                self::NAME => 'stack',
                self::ATTRIBUTES => [
                    'where' => $trace['class'] . $trace['type'] . $trace['function'],
                    'level' => (string)$level++,
                    'type' => 'file',
                    'filename' => 'file://' . $trace['file'],
                    'lineno' => (string)$trace['line'],
                ],
            ];
        }, Debugger::getCurrentCoroutineTrace()));
    }

    public static function context_names($command, $i): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
        ], [
            self::NAME => 'context',
            self::ATTRIBUTES => [
                'name' => 'Local',
                'id' => '1',
            ],
        ]);
    }

    public static function context_get($command, $i, $d, $c): string
    {
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
        ], [
            self::property(Debugger::getDefinedVars($d))
        ]);
    }

    private static function property($vars, $parentName = '$%s', $n = ''): array
    {
        $property = [];

        foreach ($vars as $key => $val) {
            $fullname = sprintf($parentName, $key);
            $type = strtolower(gettype($val));

            $attributes = [
                'name' => (string)$key,
                'fullname' => $fullname,
                'type' => $type,
            ];
            $value = null;
            $p = [self::NAME => 'property'];
            if (is_array($val)) {
                $attributes['children'] = '1';
                $attributes['numchildren'] = (string)count($val);
            } elseif (is_object($val)) {
                $attributes['children'] = '1';
                $attributes['numchildren'] = (string)count(get_object_vars($val));
                $attributes['classname'] = get_class($val);
            } elseif (is_bool($val)) {
                $attributes['type'] = 'bool';
                $value = "<![CDATA[$val]]>";
            } elseif (is_integer($val) || is_float($val)) {
                $value = "<![CDATA[$val]]>";
            } elseif (is_string($val)) {
                $attributes['size'] = (string)strlen($val);
                $attributes['encoding'] = 'base64';
                $val = base64_encode($val);
                $value = "<![CDATA[$val]]>";
            }

            $p[self::ATTRIBUTES] = $attributes;
            if (!is_null($val)) {
                $p[self::VALUE] = $value;
            }

            $property[] = $p;
        }

        if ($n) {
            return [
                self::NAME => 'property',
                self::ATTRIBUTES => [
                    'name' => $n,
                    'fullname' => $n,
                    'type' => gettype($vars),
                    'children' => '1',
                    'numchildren' => (string)(is_array($vars) ? count($vars) : count(get_object_vars($vars))),
                ],
                self::VALUE => $property
            ];
        }

        return $property;
    }

    public static function property_get($command, $i, $n, $d, $c, $p): string
    {
        $vars = Debugger::getDefinedVars($d);

        $keys = array_filter(explode('.', str_replace(["['", "']", "->"], '.', ltrim($n, '$'))), function ($k) {
            return $k !== '';
        });

        foreach ($keys as $key) {
            if (is_array($vars)) {
                $vars = $vars[$key];
            } else {
                $vars = $vars->{$key};
            }
        }
        if (is_array($vars)) {
            $parentName = $n . "['%s']";
        } else {
            $parentName = $n . "->%s";
        }

        return Xml::generate([
            'xmlns' => self::$xmlns,
            'command' => $command,
            'transaction_id' => $i,
        ], [
            self::property($vars, $parentName, $n),
        ]);
    }

    public static function step_over($command, $i): string
    {
        $trace = Debugger::getCurrentCoroutineTrace();
        $file = $trace[0]['file'] ?? '';
        $line = $trace[0]['line'] ?? '';
        return Xml::generate([
            'xmlns' => self::$xmlns,
            'xmlns:xdebug' => 'https://xdebug.org/dbgp/xdebug',
            'command' => $command,
            'transaction_id' => $i,
            'status' => 'break',
            'reason' => 'ok',
        ], [
            self::NAME => 'xdebug:message',
            self::ATTRIBUTES => [
                'filename' => 'file://' . $file,
                'lineno' => (string)$line,
            ],
        ]);
    }

    public static function run($command, $i): string
    {
        return self::step_over($command, $i);
    }
}