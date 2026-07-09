<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AssetService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class AssetController extends Controller
{
    public function __construct(public AssetService $assetService) {}

    public function index() : JsonResponse
    {
        $assets = Asset::paginate(15);
        return response()->json($assets);
    }

    public function store(Request $request) : JsonResponse
    {
        return response()->json($request, 201);

        # TODO: Finalizar integrações
        $payload = $request->validate([
            "patient_id"        => "required|integer",
            "category_id"       => "required|integer",
            "ds_id"             => "required|integer",
            "source"            => "required|string|max:255",
            "fhir"              => "required|json"
        ]);

        # TODO: Get ZKP Proof from Validator
        $encodedFhirData = json_encode(data_get($payload, "fhir", []));
        if (empty($encodedFhirData)) {
            throw new Exception("Error while encoding FHIR data to JSON.");
        }

        $encryptedFhir = Crypt::encryptString($encodedFhirData);
        $zkpProof = $this->assetService->obtainZkpProof($encryptedFhir);
        data_set($payload, $zkpProof, "no-proof");

        # TODO: Send request to save on DS

        $fhirMetadata =
            $this->assetService->extractFhirMetadata(data_get($payload, "fhir", []));

        data_set($payload, "bytes", strlen($encodedFhirData));
        data_set($payload, "created_by", 1);
        data_set($payload, "encrypt_algoritm", "AES-256");

        $asset = Asset::create($payload);

        # TODO: Get formatted HL7 FHIR metadata

        # TODO: Invoke RegisterAssetMetadata job

        return response()->json($asset, 201);
    }

    public function show(Asset $asset)
    {
        return response()->json($asset);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        return response()->json($asset);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $payload = [
            "deleted_by" => 1,     // Auth::id(),
            "active" => false
        ];
        $asset->update($payload);
        $asset->delete();
        return response()->json(null, 204);
    }
}
