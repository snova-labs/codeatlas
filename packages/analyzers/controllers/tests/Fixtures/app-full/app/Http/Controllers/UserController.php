<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Auditable;
use App\Http\Traits\HasApiResponse;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware as RouteMiddleware;

class UserController extends Controller implements Auditable
{
    use HasApiResponse;

    public function __construct(
        private readonly UserService $userService,
        private readonly ?User $viewer = null,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([]);
    }

    #[RouteMiddleware('auth')]
    public function show(Request $request, int $id, ?string $format = 'json'): JsonResponse|string
    {
        return response()->json([]);
    }

    protected function transform(User $user): array
    {
        return [];
    }
}
