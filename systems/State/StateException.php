<?php
declare(strict_types=1);
namespace Wx\Systems\State;

/** 状态规则被破坏时抛出：非法相位组合、动作越界、并发推进冲突。 */
final class StateException extends \RuntimeException
{
}
