<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

use CodeAtlas\Core\Parser\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Resolves the call sites inside a class's methods back to the classes
 * being called.
 *
 * This is what turns a dependency graph into a call graph. Constructor
 * injection tells us a service HAS a repository; call-site extraction
 * tells us which of its methods actually USE it, and which repository
 * methods they reach for:
 *
 *   public function createUser(array $data): User
 *   {
 *       $user = $this->users->create($data);        // UserRepository::create
 *       $this->mailer->sendWelcome($user);          // EmailService::sendWelcome
 *       return $user;
 *   }
 *
 * Receivers are resolved from a property map built out of promoted
 * constructor parameters, constructor body assignments, and typed
 * property declarations. Static calls on a class name resolve directly,
 * which is how `User::where(...)` inside a repository is attributed to
 * the model.
 *
 * Nothing is ever executed and nothing is guessed: a receiver that does
 * not resolve to a declared class type is skipped.
 */
final class CallSiteExtractor
{
    private readonly TypeRenderer $types;

    private readonly NodeFinder $finder;

    public function __construct(private readonly ParsedFile $file)
    {
        $this->types = new TypeRenderer($file);
        $this->finder = new NodeFinder();
    }

    /**
     * Call sites per method name, in source order.
     *
     * @return array<string, list<CallSite>>
     */
    public function extract(Class_ $class): array
    {
        $properties = $this->propertyTypes($class);
        $calls = [];

        foreach ($class->getMethods() as $method) {
            $found = $this->methodCalls($method, $properties);

            if ($found !== []) {
                $calls[$method->name->toString()] = $found;
            }
        }

        return $calls;
    }

    /**
     * Property name => fully qualified class name, for every property
     * whose type can be determined statically.
     *
     * @return array<string, string>
     */
    public function propertyTypes(Class_ $class): array
    {
        $map = [];

        // Typed property declarations: private UserRepository $users;
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            $fqcn = $this->typeFqcn($stmt->type);

            if ($fqcn === null) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                $map[$prop->name->toString()] = $fqcn;
            }
        }

        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return $map;
        }

        // Promoted constructor parameters: public function __construct(private UserRepository $users)
        $parameterTypes = [];

        foreach ($constructor->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $fqcn = $this->typeFqcn($param->type);

            if ($fqcn === null) {
                continue;
            }

            $parameterTypes[$param->var->name] = $fqcn;

            if ($param->flags !== 0) {
                $map[$param->var->name] = $fqcn;
            }
        }

        // Constructor body assignments: $this->users = $users;
        foreach ($this->assignments($constructor) as $property => $parameter) {
            if (isset($parameterTypes[$parameter])) {
                $map[$property] = $parameterTypes[$parameter];
            }
        }

        return $map;
    }

    /**
     * Property name => constructor parameter name, for each
     * `$this->property = $parameter;` statement in the constructor body.
     *
     * @return array<string, string>
     */
    private function assignments(ClassMethod $constructor): array
    {
        if ($constructor->stmts === null) {
            return [];
        }

        $assignments = [];

        /** @var list<Assign> $assigns */
        $assigns = $this->finder->findInstanceOf($constructor->stmts, Assign::class);

        foreach ($assigns as $assign) {
            $target = $assign->var;
            $source = $assign->expr;

            if (!$target instanceof PropertyFetch || !$this->isThis($target->var)) {
                continue;
            }

            if (!$target->name instanceof Identifier) {
                continue;
            }

            if (!$source instanceof Variable || !is_string($source->name)) {
                continue;
            }

            $assignments[$target->name->toString()] = $source->name;
        }

        return $assignments;
    }

    /**
     * @param array<string, string> $properties Property name => FQCN
     *
     * @return list<CallSite>
     */
    private function methodCalls(ClassMethod $method, array $properties): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $calls = [];

        /** @var list<Node> $nodes */
        $nodes = $this->finder->find($method->stmts, static fn(Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall);

        foreach ($nodes as $node) {
            $call = $node instanceof MethodCall
                ? $this->instanceCall($node, $properties)
                : $this->staticCall($node);

            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * `$this->property->method()` — the receiver's type comes from the
     * property map.
     *
     * @param array<string, string> $properties
     */
    private function instanceCall(MethodCall $call, array $properties): ?CallSite
    {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        $receiver = $call->var;

        if (!$receiver instanceof PropertyFetch || !$this->isThis($receiver->var)) {
            return null;
        }

        if (!$receiver->name instanceof Identifier) {
            return null;
        }

        $property = $receiver->name->toString();

        if (!isset($properties[$property])) {
            return null;
        }

        return new CallSite(
            targetFqcn: $properties[$property],
            method: $call->name->toString(),
            line: $call->getStartLine(),
            kind: CallSite::KIND_INSTANCE,
            via: '$this->' . $property,
        );
    }

    /**
     * `User::where(...)` — the receiver is the class name itself.
     *
     * self/static/parent are skipped: a call to the declaring class is
     * not a relationship to another node.
     */
    private function staticCall(StaticCall $call): ?CallSite
    {
        if (!$call->name instanceof Identifier || !$call->class instanceof Name) {
            return null;
        }

        // Checked on the written name: resolve() would turn "self" into
        // "App\Services\self", which no builtin check would catch.
        if (in_array(strtolower($call->class->toString()), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $fqcn = $this->types->resolve($call->class);

        if (TypeRenderer::isBuiltin($fqcn)) {
            return null;
        }

        return new CallSite(
            targetFqcn: $fqcn,
            method: $call->name->toString(),
            line: $call->getStartLine(),
            kind: CallSite::KIND_STATIC,
            via: $call->class->toString(),
        );
    }

    /**
     * Resolve a declared type to a class FQCN, or null when it is absent,
     * a builtin, or too complex to attribute to one class.
     */
    private function typeFqcn(Identifier|Name|Node\ComplexType|null $type): ?string
    {
        $inner = $type instanceof NullableType ? $type->type : $type;

        if (!$inner instanceof Name) {
            return null;
        }

        $fqcn = $this->types->resolve($inner);

        return TypeRenderer::isBuiltin($fqcn) ? null : $fqcn;
    }

    private function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }
}
