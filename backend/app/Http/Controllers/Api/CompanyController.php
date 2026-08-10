<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companies = Company::query()
            ->withCount('titles')
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('company_number', 'like', $term);
                });
            })
            ->orderByDesc('titles_count')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return CompanyResource::collection($companies);
    }

    public function show(Company $company): CompanyResource
    {
        return CompanyResource::make($company->loadCount('titles'));
    }
}
