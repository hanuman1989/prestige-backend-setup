<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnquiryRequest;
use App\Http\Resources\EnquiryResource;
use App\Models\Enquiry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

class EnquiryController extends Controller
{
    public function store(StoreEnquiryRequest $request): JsonResponse
    {
        try {
            $enquiry = Enquiry::create($request->validated())->refresh();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to submit the enquiry. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new EnquiryResource($enquiry),
            'pagination' => null,
            'message' => 'Enquiry submitted successfully.',
        ], 201);
    }
}
