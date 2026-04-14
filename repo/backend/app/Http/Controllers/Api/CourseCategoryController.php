<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseCategory;
use App\Services\AuditService;
use App\Services\MasterDataVersionService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CourseCategoryController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuditService $auditService,
        private MasterDataVersionService $versionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = CourseCategory::with('parent');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $this->paginated($query->orderBy('name')->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:course_categories,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_category_id' => 'nullable|integer|exists:course_categories,id',
        ]);

        $category = CourseCategory::create($request->only(['code', 'name', 'description', 'parent_category_id']));

        $this->versionService->trackCreate($category, 'course_category', Auth::id());

        $this->auditService->log(
            'course_category', (string) $category->id, 'course_category_created',
            Auth::id(), null, $request->ip(),
            null, $this->auditService->computeEntityHash($category->toArray())
        );

        return $this->success($category, [], 201);
    }

    public function show(CourseCategory $courseCategory): JsonResponse
    {
        $courseCategory->load(['parent', 'children']);
        $data = $courseCategory->toArray();
        $data['version_history'] = $this->versionService->getHistory('course_category', $courseCategory->id);
        return $this->success($data);
    }

    public function update(Request $request, CourseCategory $courseCategory): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'parent_category_id' => 'nullable|integer|exists:course_categories,id',
            'change_reason' => 'nullable|string|max:500',
        ]);

        $beforeSnapshot = $courseCategory->toArray();
        $courseCategory->update($request->only(['name', 'description', 'parent_category_id']));

        $this->versionService->trackUpdate($courseCategory, $beforeSnapshot, 'course_category', Auth::id(), $request->input('change_reason'));

        $this->auditService->log(
            'course_category', (string) $courseCategory->id, 'course_category_updated',
            Auth::id(), null, $request->ip()
        );

        return $this->success($courseCategory->fresh());
    }

    public function destroy(Request $request, CourseCategory $courseCategory): JsonResponse
    {
        $this->versionService->trackSoftDelete($courseCategory, 'course_category', Auth::id(), $request->input('reason'));
        $courseCategory->update(['status' => 'inactive']);
        $courseCategory->delete();

        $this->auditService->log(
            'course_category', (string) $courseCategory->id, 'course_category_soft_deleted',
            Auth::id(), null, $request->ip()
        );

        return $this->success(['message' => 'Course category soft-deleted.']);
    }
}
