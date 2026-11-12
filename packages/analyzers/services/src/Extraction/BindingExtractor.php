<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Services\Extraction;

use CodeAtlas\Core\Ast\TypeRenderer;
use CodeAtlas\Core\Parser\ParsedFile;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Extracts interface => implementation bindings from a service provider:
 *
 *   $this->app->bind(UserServiceInterface::class, UserService::class);
 *   $this->app->singleton(PaymentGateway::class, StripeGateway::class);
 *   $this->app->scoped(TenantContext::class, fn () => new TenantContext());
 *
 * This is the second half of service detection. The app/Services/
 * convention finds services by where they live; bindings find them by
 * how they are resolved, which catches services that live elsewhere and
 * supplies the `interface` field of the Services result schema.
 *
 * Only statically determinable bindings are recorded. A closure that
 * returns `new X(...)` is read for its class; a closure that builds its
 * result dynamically is skipped rather than guessed at, since resolving
 * it would mean executing application code.
 *
 * Typed against the concrete ParsedFile because it needs the AST
 * helpers, which ParsedFileInterface intentionally omits to keep the
 * contracts package free of a PhpParser\Node import.
 */
final class BindingExtractor
{
    /**
     * Container methods that register an abstract => concrete binding.
     */
    private const BINDING_METHODS = ['bind', 'singleton', 'scoped', 'bindIf', 'singletonIf', 'instance'];

    private readonly TypeRenderer $types;

    private readonly NodeFinder $finder;

    public function __construct(private readonly ParsedFile $file)
    {
        $this->types = new TypeRenderer($file);
        $this->finder = new NodeFinder();
    }

    /**
     * Abstract (interface) FQCN => concrete implementation FQCN.
     *
     * @return array<string, string>
     */
    public function extract(): array
    {
        $bindings = [];

        /** @var list<MethodCall> $calls */
        $calls = $this->finder->findInstanceOf($this->file->ast(), MethodCall::class);

        foreach ($calls as $call) {
            if (!$call->name instanceof Identifier) {
                continue;
            }

            if (!in_array($call->name->toString(), self::BINDING_METHODS, true)) {
                continue;
            }

            $args = array_values(array_filter(
                $call->args,
                static fn(mixed $arg): bool => $arg instanceof Arg,
            ));

            if (count($args) < 2) {
                continue;
            }

            $abstract = $this->classReference($args[0]->value);
            $concrete = $this->concrete($args[1]->value);

            if ($abstract === null || $concrete === null || $abstract === $concrete) {
                continue;
            }

            $bindings[$abstract] = $concrete;
        }

        return $bindings;
    }

    /**
     * The concrete side of a binding: a class reference, or the class
     * instantiated by a single-expression factory closure.
     */
    private function concrete(Expr $expr): ?string
    {
        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            $new = $this->finder->findFirstInstanceOf(
                $expr instanceof Closure ? $expr->stmts : [$expr->expr],
                New_::class,
            );

            return $new instanceof New_ && $new->class instanceof Name
                ? $this->types->resolve($new->class)
                : null;
        }

        return $this->classReference($expr);
    }

    /**
     * `Foo::class` or a plain "App\Foo" string literal.
     */
    private function classReference(Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch) {
            if (!$expr->name instanceof Identifier || $expr->name->toString() !== 'class') {
                return null;
            }

            return $expr->class instanceof Name ? $this->types->resolve($expr->class) : null;
        }

        if ($expr instanceof String_) {
            $value = ltrim($expr->value, '\\');

            return str_contains($value, '\\') ? $value : null;
        }

        return null;
    }
}
