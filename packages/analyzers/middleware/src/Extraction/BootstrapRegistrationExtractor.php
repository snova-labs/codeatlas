<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware\Extraction;

use CodeAtlas\Analyzers\Middleware\DTOs\RegistrationData;
use CodeAtlas\Contracts\ParsedFileInterface;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Extracts middleware registration from a Laravel 11 bootstrap/app.php:
 *
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias(['admin' => EnsureAdmin::class]);
 *       $middleware->append(GlobalMw::class);           // global
 *       $middleware->prepend(FirstMw::class);           // global
 *       $middleware->appendToGroup('api', Throttle::class);
 *       $middleware->prependToGroup('web', X::class);
 *       $middleware->web(append: [A::class]);           // group shorthand
 *       $middleware->api(prepend: [B::class]);
 *       $middleware->priority([...]);
 *   })
 *
 * The extractor finds every MethodCall on the closure's parameter inside
 * the withMiddleware closure — purely via AST, resilient to how the
 * builder chain is formatted.
 */
final class BootstrapRegistrationExtractor
{
    public function __construct(private readonly ParsedFileInterface $file) {}

    public function extract(): RegistrationData
    {
        $closure = $this->findWithMiddlewareClosure();

        if ($closure === null) {
            return new RegistrationData();
        }

        $paramName = $this->closureParamName($closure);

        if ($paramName === null) {
            return new RegistrationData();
        }

        $aliases = [];
        $groups = [];
        $global = [];
        $priority = [];

        foreach ($this->methodCallsOn($closure, $paramName) as $call) {
            $method = $call->name instanceof Identifier ? $call->name->toString() : '';
            $args = array_values($call->getArgs());

            switch ($method) {
                case 'alias':
                    $aliases = [...$aliases, ...$this->classMap($args[0] ?? null)];
                    break;

                case 'append':
                case 'prepend':
                case 'use':
                    $global = [...$global, ...$this->classList($args[0] ?? null)];
                    break;

                case 'appendToGroup':
                case 'prependToGroup':
                    $group = isset($args[0]) ? $this->string($args[0]->value) : null;
                    if ($group !== null) {
                        $groups[$group] = [...($groups[$group] ?? []), ...$this->classList($args[1] ?? null)];
                    }
                    break;

                case 'web':
                case 'api':
                    foreach ($args as $arg) {
                        $groups[$method] = [...($groups[$method] ?? []), ...$this->classList($arg)];
                    }
                    break;

                case 'priority':
                    $priority = $this->classList($args[0] ?? null);
                    break;
            }
        }

        return new RegistrationData(
            aliases: $aliases,
            groups: array_map(array_values(...), $groups),
            global: array_values(array_unique($global)),
            priority: $priority,
        );
    }

    private function findWithMiddlewareClosure(): ?Closure
    {
        foreach ($this->file->findNodes(MethodCall::class) as $call) {
            if (!$call->name instanceof Identifier || $call->name->toString() !== 'withMiddleware') {
                continue;
            }

            foreach ($call->getArgs() as $arg) {
                if ($arg->value instanceof Closure) {
                    return $arg->value;
                }
            }
        }

        return null;
    }

    private function closureParamName(Closure $closure): ?string
    {
        $param = $closure->params[0] ?? null;

        if ($param === null || !$param->var instanceof \PhpParser\Node\Expr\Variable) {
            return null;
        }

        return is_string($param->var->name) ? $param->var->name : null;
    }

    /**
     * @return list<MethodCall>
     */
    private function methodCallsOn(Closure $closure, string $paramName): array
    {
        $calls = [];

        foreach ($closure->stmts as $stmt) {
            if (!$stmt instanceof \PhpParser\Node\Stmt\Expression) {
                continue;
            }

            // Unwind chains: $middleware->a(...)->b(...) — collect every hop
            $expr = $stmt->expr;
            while ($expr instanceof MethodCall) {
                $calls[] = $expr;
                $expr = $expr->var;
            }

            $last = end($calls);
            if ($last !== false && !$this->rootIsVariable($stmt->expr, $paramName)) {
                // Chain not rooted at the middleware param — discard hops we just added
                $calls = array_slice($calls, 0, count($calls) - $this->chainLength($stmt->expr));
            }
        }

        return array_reverse($calls);
    }

    private function rootIsVariable(Expr $expr, string $name): bool
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof \PhpParser\Node\Expr\Variable && $expr->name === $name;
    }

    private function chainLength(Expr $expr): int
    {
        $n = 0;

        while ($expr instanceof MethodCall) {
            $n++;
            $expr = $expr->var;
        }

        return $n;
    }

    /**
     * ['alias' => X::class, ...] → [alias => FQCN]
     *
     * @return array<string, string>
     */
    private function classMap(?Arg $arg): array
    {
        if ($arg === null || !$arg->value instanceof Array_) {
            return [];
        }

        $map = [];

        foreach ($arg->value->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $key = $this->string($item->key);
            $fqcn = $this->fqcn($item->value);

            if ($key !== null && $fqcn !== null) {
                $map[$key] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * X::class or [X::class, Y::class] → list of FQCNs.
     *
     * @return list<string>
     */
    private function classList(?Arg $arg): array
    {
        if ($arg === null) {
            return [];
        }

        $value = $arg->value;

        $single = $this->fqcn($value);
        if ($single !== null) {
            return [$single];
        }

        if (!$value instanceof Array_) {
            return [];
        }

        $out = [];

        foreach ($value->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $fqcn = $this->fqcn($item->value);
            if ($fqcn !== null) {
                $out[] = $fqcn;
            }
        }

        return $out;
    }

    private function fqcn(Expr $expr): ?string
    {
        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name) {
            return null;
        }

        $const = $expr->name;
        if (!$const instanceof Identifier || $const->toString() !== 'class') {
            return null;
        }

        // \Absolute\Refs parse as FullyQualified — already resolved; running
        // them through use-statement resolution would wrongly prepend the
        // file's namespace.
        if ($expr->class->isFullyQualified()) {
            return ltrim($expr->class->toString(), '\\');
        }

        return $this->file->resolveClassName($expr->class->toString());
    }

    private function string(Expr $expr): ?string
    {
        return $expr instanceof String_ ? $expr->value : null;
    }
}
