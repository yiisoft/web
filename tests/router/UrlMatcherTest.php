<?php


namespace yii\web\tests\router;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use yii\web\router\Group;
use yii\web\router\MatchingResult;
use yii\web\router\NoHandler;
use yii\web\router\NoMatch;
use yii\web\router\Route;
use yii\web\router\UrlMatcherInterface;

class UrlMatcherTest extends TestCase
{
    private function getMatcher(array $routes): UrlMatcherInterface
    {
        return new Group($routes);
    }

    public function testMethodMismatch()
    {
        $request = new ServerRequest('GET', '/');

        $matcher = $this->getMatcher([
            Route::post('/')
        ]);


        $this->expectException(NoMatch::class);
        $matcher->match($request);
    }

    public function testHostMismatch()
    {
        $request = new ServerRequest('GET', '/');
        $uri = $request->getUri();
        $uri = $uri->withHost('https://example.com/');
        $request = $request->withUri($uri);

        $matcher = $this->getMatcher([
            Route::get('/')->host('https://yiiframework.com/'),
        ]);

        $this->expectException(NoMatch::class);
        $matcher->match($request);
    }

    public function testMatchWithNoHandler()
    {
        $request = new ServerRequest('GET', '/');
        $matcher = $this->getMatcher([
            Route::get('/')
        ]);

        $this->expectException(NoHandler::class);
        $matcher->match($request);
    }

    public function testStaticMatch()
    {
        $handler = function () {
            // does not matter
        };
        $request = new ServerRequest('GET', '/');
        $matcher = $this->getMatcher([
            Route::get('/')->to($handler)
        ]);

        $match = $matcher->match($request);
        $this->assertSame($handler, $match->getHandler());
        $this->assertEmpty($match->getParameters());
        $this->assertNull($match->getName());
    }

    public function patternMatchProvider()
    {
        $handler = function () {
            // does not matter
        };

        return [
            [
                'book/123/this+is+sample',
                new MatchingResult($handler, ['id' => '123', 'title' => 'this+is+sample'], 'book/view')
            ],
            [
                // trailing slash is significant, no match
                'book/123/this+is+sample/',
                null
            ],
            [
                '',
                null
            ],
            [
                'site/index',
                null
            ]
        ];
    }

    /**
     * @dataProvider patternMatchProvider
     */
    public function testPatternMatch(string $path, ?MatchingResult $expectedMatch)
    {
        $handler = function () {
            // does not matter
        };

        $matcher = $this->getMatcher([
            Route::get('post/<id:\d+>')
                ->to($handler)
                ->name('post/view'),
            Route::get('posts')
                ->to($handler)
                ->name('post/list'),
            Route::get('book/<id:\d+>/<title>')
                ->to($handler)
                ->name('book/view')
        ]);

        // matching pathinfo
        $request = new ServerRequest('GET', $path);
        if ($expectedMatch === null) {
            $this->expectException(NoMatch::class);
        }

        $match = $matcher->match($request);

        $this->assertSame($expectedMatch->getName(), $match->getName());
        $this->assertSame($expectedMatch->getParameters(), $match->getParameters());
    }
}
