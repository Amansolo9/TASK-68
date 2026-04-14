<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataDictionary;
use App\Services\AuditService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DataDictionaryController extends Controller
{
    use HasApiResponse;

    public function __construct(private AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $query = DataDictionary::query();

        if ($request->has('type')) {
            $query->byType($request->input('type'));
        }
        if ($request->has('active_only') && $request->boolean('active_only')) {
            $query->active();
        }

        $dictionaries = $query->orderBy('dictionary_type')->orderBy('sort_order')->paginate($request->input('per_page', 50));

        return $this->paginated($dictionaries);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'dictionary_type' => 'required|string|max:100',
            'code' => 'required|string|max:100|unique:data_dictionaries,code,NULL,id,dictionary_type,' . $request->input('dictionary_type'),
            'label' => 'required|string|max:255',
            'description' => 'nullable|string',
            'validation_rule_ref' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $dictionary = DataDictionary::create($request->only([
            'dictionary_type', 'code', 'label', 'description', 'validation_rule_ref', 'sort_order',
        ]));

        $this->auditService->log(
            'data_dictionary', (string) $dictionary->id, 'dictionary_created',
            Auth::id(), null, $request->ip(),
            null,
            $this->auditService->computeEntityHash($dictionary->toArray())
        );

        return $this->success($dictionary, [], 201);
    }

    public function show(DataDictionary $dictionary): JsonResponse
    {
        return $this->success($dictionary);
    }

    public function update(Request $request, DataDictionary $dictionary): JsonResponse
    {
        $request->validate([
            'label' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'validation_rule_ref' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $beforeHash = $this->auditService->computeEntityHash($dictionary->toArray());

        $dictionary->update($request->only([
            'label', 'description', 'validation_rule_ref', 'sort_order', 'is_active',
        ]));

        $this->auditService->log(
            'data_dictionary', (string) $dictionary->id, 'dictionary_updated',
            Auth::id(), null, $request->ip(),
            $beforeHash,
            $this->auditService->computeEntityHash($dictionary->fresh()->toArray())
        );

        return $this->success($dictionary->fresh());
    }

    public function destroy(DataDictionary $dictionary): JsonResponse
    {
        $dictionary->update(['is_active' => false]);
        $dictionary->delete(); // Soft delete

        $this->auditService->log(
            'data_dictionary', (string) $dictionary->id, 'dictionary_soft_deleted',
            Auth::id(), null, request()->ip()
        );

        return $this->success(['message' => 'Dictionary entry soft-deleted.']);
    }

    public function lookup(string $type): JsonResponse
    {
        $entries = DataDictionary::byType($type)->active()->orderBy('sort_order')->get(['code', 'label', 'description']);
        return response()->json([
            'data' => $entries,
            'meta' => ['correlation_id' => request()->header('X-Correlation-ID', '')],
            'error' => null,
        ]);
    }
}
