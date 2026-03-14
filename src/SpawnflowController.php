<?php

namespace Spawnflow;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SpawnflowController extends Controller
{
    public function index(Request $request, string $subject): JsonResponse
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve($subject)
            ->list();
    }

    public function store(Request $request, string $subject): JsonResponse
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve($subject)
            ->fields()
            ->validate()
            ->save($request->all())
            ->present(statusCode: 201);
    }

    public function update(Request $request, string $subject, int $id): JsonResponse
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve($subject)
            ->ask('POST', $id)
            ->fields()
            ->validate()
            ->save($request->all())
            ->present();
    }

    public function destroy(Request $request, string $subject, int $id): JsonResponse
    {
        return (new Flow)
            ->spawn($request)->auth()
            ->resolve($subject)
            ->ask('DELETE', $id)
            ->delete($id);
    }
}
