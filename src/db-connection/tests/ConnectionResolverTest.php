<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\DbConnection;

use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Database\ConnectionInterface;
use Hyperf\DbConnection\ConnectionResolver;
use Hyperf\DbConnection\Pool\DbPool;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Pool\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine\Channel;

/**
 * 协程连接隔离（08-11）：Coroutine::fork 复制父协程 Context（含 DB 连接）→
 * 子协程共享同一连接 → Swoole\Error（Socket has already been bound to another coroutine）→ Fatal worker 崩。
 * ConnectionResolver 按协程隔离（.cid 校验），fork 子协程重新从池取独立连接。
 *
 * @internal
 * @coversNothing
 */
#[CoversNothing]
class ConnectionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        // co-phpunit 整个套件运行在单协程内——Context 跨测试残留，需清理连接 key
        Context::set('database.connection.default', null);
        Context::set('database.connection.default.cid', null);
    }

    /**
     * 同协程多次取连接 → 复用同一连接（Context 命中，池只取一次）。
     */
    public function testReuseConnectionInSameCoroutine(): void
    {
        $gets = 0;
        $resolver = $this->makeResolver($this->mockPool($gets));

        $c1 = $resolver->connection();
        $c2 = $resolver->connection();

        $this->assertSame($c1, $c2);
        $this->assertSame(1, $gets);
    }

    /**
     * fork 子协程（Context 复制父协程含连接）→ 不共享父连接，重新从池取独立连接。
     */
    public function testIsolationAcrossFork(): void
    {
        $gets = 0;
        $resolver = $this->makeResolver($this->mockPool($gets));

        $c1 = $resolver->connection();
        $this->assertSame(1, $gets);

        $child = new Channel(1);
        Coroutine::fork(function () use ($resolver, &$gets, $child) {
            $c2 = $resolver->connection();
            $child->push($c2);
        });

        $c2 = $child->pop(5);
        $this->assertNotSame($c1, $c2);
        $this->assertSame(2, $gets);
    }

    /**
     * fork 子协程重新取连接后，父协程 Context 的连接不受影响（仍为父连接，cid 隔离）。
     */
    public function testForkDoesNotPolluteParentContext(): void
    {
        $gets = 0;
        $resolver = $this->makeResolver($this->mockPool($gets));

        $c1 = $resolver->connection();
        $this->assertSame(1, $gets);

        $child = new Channel(1);
        Coroutine::fork(function () use ($resolver, $child) {
            $resolver->connection();
            $child->push(true);
        });
        $child->pop(5);

        // 子协程的重新取不污染父协程 Context（父连接仍有效）
        $this->assertSame($c1, $resolver->connection());
        $this->assertSame(2, $gets);
    }

    protected function makeResolver(DbPool $pool): ConnectionResolver
    {
        $factory = Mockery::mock(PoolFactory::class);
        $factory->shouldReceive('getPool')->with('default')->andReturn($pool);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(PoolFactory::class)->andReturn($factory);

        return new ConnectionResolver($container);
    }

    protected function mockPool(int &$gets): DbPool
    {
        $pool = Mockery::mock(DbPool::class);
        $pool->shouldReceive('get')->andReturnUsing(function () use (&$gets) {
            ++$gets;
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getConnection')->andReturn(Mockery::mock(ConnectionInterface::class));
            $connection->shouldReceive('release');
            return $connection;
        });

        return $pool;
    }
}
