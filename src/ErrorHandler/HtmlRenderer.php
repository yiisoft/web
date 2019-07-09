<?php

namespace Yiisoft\Yii\Web\ErrorHandler;

use http\Exception\RuntimeException;
use Yiisoft\VarDumper\VarDumper;

class HtmlRenderer implements ErrorRendererInterface
{
    private const MAX_SOURCE_LINES = 19;
    private const MAX_TRACE_LINES = 13;

    public $displayVars = ['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION'];
    public $traceLine = '{html}';

    public function render(\Throwable $e): string
    {
        return $this->renderTemplate('exception', [
            'exception' => $e,
        ]);
    }

    private function htmlEncode(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function renderTemplate(string $template, array $params): string
    {
        $path = __DIR__ . '/templates/' . $template . '.php';
        if (!file_exists($path)) {
            throw new RuntimeException("$template not found at $path");
        }

        $renderer = function () use ($path, $params) {
            extract($params, EXTR_OVERWRITE);
            require $path;
        };

        $obInitialLevel = ob_get_level();
        ob_start();
        ob_implicit_flush(false);
        try {
            $renderer->bindTo($this)();
            return ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $obInitialLevel) {
                if (!@ob_end_clean()) {
                    ob_clean();
                }
            }
            throw $e;
        }
    }

    /**
     * Renders the previous exception stack for a given Exception.
     * @param \Exception $exception the exception whose precursors should be rendered.
     * @return string HTML content of the rendered previous exceptions.
     * Empty string if there are none.
     */
    private function renderPreviousExceptions($exception)
    {
        if (($previous = $exception->getPrevious()) !== null) {
            return $this->renderTemplate('previousException', ['exception' => $previous]);
        }
        return '';
    }

    /**
     * Renders a single call stack element.
     * @param string|null $file name where call has happened.
     * @param int|null $line number on which call has happened.
     * @param string|null $class called class name.
     * @param string|null $method called function/method name.
     * @param array $args array of method arguments.
     * @param int $index number of the call stack element.
     * @return string HTML content of the rendered call stack element.
     */
    private function renderCallStackItem($file, $line, $class, $method, $args, $index): string
    {
        $lines = [];
        $begin = $end = 0;
        if ($file !== null && $line !== null) {
            $line--; // adjust line number from one-based to zero-based
            $lines = @file($file);
            if ($line < 0 || $lines === false || ($lineCount = count($lines)) < $line) {
                return '';
            }
            $half = (int)(($index === 1 ? self::MAX_SOURCE_LINES : self::MAX_TRACE_LINES) / 2);
            $begin = $line - $half > 0 ? $line - $half : 0;
            $end = $line + $half < $lineCount ? $line + $half : $lineCount - 1;
        }
        return $this->renderTemplate('callStackItem', [
            'file' => $file,
            'line' => $line,
            'class' => $class,
            'method' => $method,
            'index' => $index,
            'lines' => $lines,
            'begin' => $begin,
            'end' => $end,
            'args' => $args,
        ]);
    }

    /**
     * Renders call stack.
     * @param \Exception|\ParseError $exception exception to get call stack from
     * @return string HTML content of the rendered call stack.
     * @since 2.0.12
     */
    private function renderCallStack($exception)
    {
        $out = '<ul>';
        $out .= $this->renderCallStackItem($exception->getFile(), $exception->getLine(), null, null, [], 1);
        for ($i = 0, $trace = $exception->getTrace(), $length = count($trace); $i < $length; ++$i) {
            $file = !empty($trace[$i]['file']) ? $trace[$i]['file'] : null;
            $line = !empty($trace[$i]['line']) ? $trace[$i]['line'] : null;
            $class = !empty($trace[$i]['class']) ? $trace[$i]['class'] : null;
            $function = null;
            if (!empty($trace[$i]['function']) && $trace[$i]['function'] !== 'unknown') {
                $function = $trace[$i]['function'];
            }
            $args = !empty($trace[$i]['args']) ? $trace[$i]['args'] : [];
            $out .= $this->renderCallStackItem($file, $line, $class, $function, $args, $i + 2);
        }
        $out .= '</ul>';
        return $out;
    }

    /**
     * Determines whether given name of the file belongs to the framework.
     * @param string $file name to be checked.
     * @return bool whether given name of the file belongs to the framework.
     */
    private function isCoreFile($file)
    {
        return false;
        //return $file === null || strpos(realpath($file), YII2_PATH . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Adds informational links to the given PHP type/class.
     * @param string $code type/class name to be linkified.
     * @return string linkified with HTML type/class name.
     */
    private function addTypeLinks($code)
    {
        if (preg_match('/(.*?)::([^(]+)/', $code, $matches)) {
            $class = $matches[1];
            $method = $matches[2];
            $text = $this->htmlEncode($class) . '::' . $this->htmlEncode($method);
        } else {
            $class = $code;
            $method = null;
            $text = $this->htmlEncode($class);
        }
        $url = null;
        $shouldGenerateLink = true;
        if ($method !== null && substr_compare($method, '{closure}', -9) !== 0) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->hasMethod($method)) {
                $reflectionMethod = $reflection->getMethod($method);
                $shouldGenerateLink = $reflectionMethod->isPublic() || $reflectionMethod->isProtected();
            } else {
                $shouldGenerateLink = false;
            }
        }
        if ($shouldGenerateLink) {
            $url = $this->getTypeUrl($class, $method);
        }
        if ($url === null) {
            return $text;
        }
        return '<a href="' . $url . '" target="_blank">' . $text . '</a>';
    }

    /**
     * Returns the informational link URL for a given PHP type/class.
     * @param string $class the type or class name.
     * @param string|null $method the method name.
     * @return string|null the informational link URL.
     * @see addTypeLinks()
     */
    private function getTypeUrl($class, $method)
    {
        if (strncmp($class, 'yii\\', 4) !== 0) {
            return null;
        }
        $page = $this->htmlEncode(strtolower(str_replace('\\', '-', $class)));
        $url = "http://www.yiiframework.com/doc-2.0/$page.html";
        if ($method) {
            $url .= "#$method()-detail";
        }
        return $url;
    }

    /**
     * Converts arguments array to its string representation.
     *
     * @param array $args arguments array to be converted
     * @return string string representation of the arguments array
     */
    private function argumentsToString($args)
    {
        $count = 0;
        $isAssoc = $args !== array_values($args);
        foreach ($args as $key => $value) {
            $count++;
            if ($count >= 5) {
                if ($count > 5) {
                    unset($args[$key]);
                } else {
                    $args[$key] = '...';
                }
                continue;
            }
            if (is_object($value)) {
                $args[$key] = '<span class="title">' . $this->htmlEncode(get_class($value)) . '</span>';
            } elseif (is_bool($value)) {
                $args[$key] = '<span class="keyword">' . ($value ? 'true' : 'false') . '</span>';
            } elseif (is_string($value)) {
                $fullValue = $this->htmlEncode($value);
                if (mb_strlen($value, 'UTF-8') > 32) {
                    $displayValue = $this->htmlEncode(mb_substr($value, 0, 32, 'UTF-8')) . '...';
                    $args[$key] = "<span class=\"string\" title=\"$fullValue\">'$displayValue'</span>";
                } else {
                    $args[$key] = "<span class=\"string\">'$fullValue'</span>";
                }
            } elseif (is_array($value)) {
                $args[$key] = '[' . $this->argumentsToString($value) . ']';
            } elseif ($value === null) {
                $args[$key] = '<span class="keyword">null</span>';
            } elseif (is_resource($value)) {
                $args[$key] = '<span class="keyword">resource</span>';
            } else {
                $args[$key] = '<span class="number">' . $value . '</span>';
            }
            if (is_string($key)) {
                $args[$key] = '<span class="string">\'' . $this->htmlEncode($key) . "'</span> => $args[$key]";
            } elseif ($isAssoc) {
                $args[$key] = "<span class=\"number\">$key</span> => $args[$key]";
            }
        }
        return implode(', ', $args);
    }

    /**
     * Renders the global variables of the request.
     * List of global variables is defined in [[displayVars]].
     * @return string the rendering result
     * @see displayVars
     */
    private function renderRequest()
    {
        $request = '';
        foreach ($this->displayVars as $name) {
            if (!empty($GLOBALS[$name])) {
                $request .= '$' . $name . ' = ' . VarDumper::export($GLOBALS[$name]) . ";\n\n";
            }
        }
        return '<pre>' . $this->htmlEncode(rtrim($request, "\n")) . '</pre>';
    }


    /**
     * Creates string containing HTML link which refers to the home page of determined web-server software
     * and its full name.
     * @return string server software information hyperlink.
     */
    private function createServerInformationLink()
    {
        $serverUrls = [
            'http://httpd.apache.org/' => ['apache'],
            'http://nginx.org/' => ['nginx'],
            'http://lighttpd.net/' => ['lighttpd'],
            'http://gwan.com/' => ['g-wan', 'gwan'],
            'http://iis.net/' => ['iis', 'services'],
            'https://secure.php.net/manual/en/features.commandline.webserver.php' => ['development'],
        ];
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            foreach ($serverUrls as $url => $keywords) {
                foreach ($keywords as $keyword) {
                    if (stripos($_SERVER['SERVER_SOFTWARE'], $keyword) !== false) {
                        return '<a href="' . $url . '" target="_blank">' . $this->htmlEncode($_SERVER['SERVER_SOFTWARE']) . '</a>';
                    }
                }
            }
        }
        return '';
    }

    /**
     * Creates string containing HTML link which refers to the page with the current version
     * of the framework and version number text.
     * @return string framework version information hyperlink.
     */
    public function createFrameworkVersionLink()
    {
        return '<a href="http://github.com/yiisoft/yii2/" target="_blank">' . $this->htmlEncode('3.0.0') . '</a>';
    }
}
