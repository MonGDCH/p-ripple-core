<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Library\System\Process;

use Closure;
use Fiber;
use P\Coroutine;
use Psc\Core\LibraryAbstract;
use Psc\Core\Output;
use Psc\Library\Coroutine\EscapeException;
use Psc\Library\System\Exception\ProcessException;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function count;
use function P\cancel;
use function P\cancelAll;
use function P\getIdentities;
use function P\promise;
use function P\tick;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_getpid;

use const SIGCHLD;
use const SIGKILL;
use const WNOHANG;
use const WUNTRACED;

/**
 *
 */
class Process extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var array */
    private array $process2promiseCallback = array();

    /*** @var Runtime[] */
    private array $process2runtime = array();

    /*** @var array */
    private array $onFork = array();

    /*** @var int */
    private int $rootProcessId;

    /*** @var int */
    private int $processId;

    /*** @var string */
    private string $signalHandlerEventId;

    public function __construct()
    {
        $this->rootProcessId = posix_getpid();
        $this->processId = posix_getpid();
    }


    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignalHandler(): void
    {
        $this->signalHandlerEventId = $this->onSignal(SIGCHLD, fn () => $this->signalSIGCHLDHandler());
    }

    /**
     * @return void
     */
    private function unregisterSignalHandler(): void
    {
        cancel($this->signalHandlerEventId);
    }

    /**
     * @return void
     */
    private function signalSIGCHLDHandler(): void
    {
        while (1) {
            $childrenId = pcntl_wait($status, WNOHANG | WUNTRACED);

            if ($childrenId <= 0) {
                break;
            }

            $this->onProcessExit($childrenId, $status);
        }
    }

    /**
     * @param int $processId
     * @param int $status
     * @return void
     */
    private function onProcessExit(int $processId, int $status): void
    {
        $exit            = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        $promiseCallback = $this->process2promiseCallback[$processId] ?? null;
        if (!$promiseCallback) {
            return;
        }

        if ($exit === -1) {
            call_user_func($promiseCallback['reject'], new ProcessException('The process is abnormal.', $exit));
        } else {
            call_user_func($promiseCallback['resolve'], $exit);
        }

        unset($this->process2promiseCallback[$processId]);
        unset($this->process2runtime[$processId]);

        if(empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }
    }

    /**
     * @return void
     */
    private function destroy(): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal(SIGKILL);
        }
    }

    /*** @var int */
    private int $index = 0;

    /**
     * @param Closure $closure
     * @return int
     */
    public function registerForkHandler(Closure $closure): int
    {
        $this->onFork[$this->index] = $closure;
        return $this->index++;
    }

    /**
     * @param int $index
     * @return void
     */
    public function cancelForkHandler(int $index): void
    {
        unset($this->onFork[$index]);
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException|Throwable
     */
    public function forked(): void
    {
        if (!empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }

        foreach ($this->onFork as $key => $closure) {
            try {
                unset($this->onFork[$key]);
                $closure();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        $this->process2promiseCallback = array();
        $this->process2runtime         = array();
        $this->processId               = posix_getpid();
    }

    /**
     * @param Closure $closure
     * @return Task|false
     */
    public function task(Closure $closure): Task|false
    {
        return new Task(function (...$args) use ($closure) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                Output::warning('Fork failed.');
                return false;
            }

            if ($processId === 0) {
                /**
                 * It is necessary to ensure that the final closure cannot be escaped by any means.
                 */

                // Whether it belongs to the PRipple coroutine space
                $isCoroutine = Coroutine::Coroutine()->isCoroutine();

                // forget all events
                cancelAll();

                // Handle recycling and new process mounting
                $this->forked();

                // call user mount
                try {
                    call_user_func($closure, ...$args);
                } catch (Throwable) {
                    exit(1);
                }

                // Determine whether the event list is empty
                if(count(getIdentities()) === 0) {
                    exit(0);
                }

                if(!$isCoroutine) {
                    if(Fiber::getCurrent()) {
                        Fiber::suspend();
                    }
                    tick();
                    exit(0);
                }

                throw new EscapeException('The process is abnormal.');
            }

            if(empty($this->process2runtime)) {
                $this->registerSignalHandler();
            }

            $promise = promise(function ($r, $d) use ($processId) {
                $this->process2promiseCallback[$processId] = array(
                    'resolve' => $r,
                    'reject'  => $d,
                );
            });

            $runtime = new Runtime(
                $promise,
                $processId,
            );

            $this->process2runtime[$processId] = $runtime;
            return $runtime;
        });
    }

    /**
     * @param int     $signalCode
     * @param Closure $handler
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signalCode, Closure $handler): string
    {
        return EventLoop::onSignal($signalCode, $handler);
    }

    /**
     * @return int
     */
    public function getProcessId(): int
    {
        return $this->processId;
    }

    /**
     * @return int
     */
    public function getMainProcessId(): int
    {
        return $this->rootProcessId;
    }

    /**
     * @return bool
     */
    public function isRootProcess(): bool
    {
        return $this->processId === $this->rootProcessId;
    }
}
