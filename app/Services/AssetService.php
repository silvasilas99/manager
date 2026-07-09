<?php

namespace App\Services;

use App\Jobs\RegisterAssetMetadata;
use App\Models\Asset;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AssetService
{
    public function __construct(
        public Asset $assetModel,
        public RegisterAssetMetadata $registerAssetMetadataJob
    ) {}

    public function getAllAssets () {}
    public function getAssetById (int $id) {}
    public function createAsset (array $data) {}
    public function updateAssetById (int $id, $data) {}
    public function deleteAssetById (int $id) {}

    public function extractFhirMetadata(array $payload) : array
    {
        $fhirCategory = data_get($payload, "resourceType", "no-resource-type");
        $flattenedFhirData = Arr::dot($payload);
        $extractedMetadataList = collect($flattenedFhirData)
            ->map(function ($metadataValue, $metadataName) use ($fhirCategory) {
                return [
                    "name" => $metadataName,
                    "category" => $this->classifyMetadataField($metadataName, $fhirCategory),
                    "primitive_type" => gettype($metadataValue),
                    "value" => $metadataValue
                ];
            })->values()->toArray();
        return [
            "fhir_category" => $fhirCategory,
            "total_fields_extracted" => count($extractedMetadataList),
            "metadata" => $extractedMetadataList
        ];
    }

    public function insertAssetMetadataOnChain (
        array $fhirMetadata,
        int $patientId = 1,
        int $assetId = 1,
        int $createdBy = 1
    ) : void
    {
        collect($fhirMetadata)
            ->map(function ($metadataValue, $metadataKey) use ($patientId, $assetId, $createdBy)
            {
                try {
                    $metadataValue =
                        is_string($metadataValue)
                            ? $metadataValue
                            : json_encode($metadataValue);
                    $payloadToSend = [
                        "patient_id" => $patientId,
                        "asset_id" => $assetId,
                        "name" => (string) $metadataKey,
                        "value" => $metadataValue,
                        "version" => json_encode([$metadataValue]),
                        "owner" => 1,
                        "rights" => "NSA",
                        "terms_of_access" => "NSA",
                        "created_by" => $createdBy
                    ];
                    $response = Http::post("http://localhost:8080/api" . "/metadata", $payloadToSend);
                    Log::debug(
                        "[AssetService][insertAssetMetadataOnChain] Response from request:",
                        [
                            "response_status" => $response->getStagetStatusCode(),
                            "response_message" => $response->getReasonPhrase(),
                            "payload_sent" => $payloadToSend
                        ]
                    );
                } catch (Exception $e) {
                    Log::error("[AssetService][insertAssetMetadataOnChain] Error: " . $e->getMessage());
                    throw $e;
                }
            });
    }

    public function obtainZkpProof($fhirData) : string {
        # TODO: Integrate with Validator modulo to obtain real proof.
        return "proof";
    }

    private function classifyMetadataField(string $path, string $resourceType): string
    {
        // Extrai apenas o último fragmento do path para a análise da chave (ignora índices numéricos)
        $keySegments = array_filter(explode(".", $path), fn($segment) => !is_numeric($segment));
        $key = end($keySegments) ?: $path;

        // Utilizamos switch (true) para avaliar as múltiplas lógicas compostas de forma limpa
        switch (true) {
            // Categoria 1: Administrativo e Segurança - Suporta a governança, gestão e administração.
            case in_array($key, ["id", "implicitRules", "language", "extension", "modifierExtension", "security"]):
                return "Administrative / Security-related";

            // Categoria 2: Proveniência de Dados - Fornece um registro detalhado da linhagem e evolução do dataset.
            case str_contains($path, "meta"):
            case in_array($key, ["meta", "versionId", "lastUpdated", "source", "profile"]):
                return "Data Provenance";

            // Categoria 3: Temporais - Fornece informações temporais sobre os dados coletados.
            case in_array($key, ["start", "end", "effectiveDateTime", "issued", "period", "date"]):
                return "Time-related";

            // Categoria 4: Relacionados a Paciente / Pessoa - Inclui informações demográficas e histórico.
            case $resourceType === "Patient":
            case str_contains(strtolower($path), "patient"):
            case in_array($key, ["gender", "birthDate", "deceased", "multipleBirth"]):
                return "Patient-related / Person-related";

            // Categoria 5: Relacionados a Observações Clínicas e Dispositivos - Inclui dados coletados e dispositivos.
            case $resourceType === "Observation":
            case in_array($key, ["code", "valueQuantity", "valueCodeableConcept", "component", "interpretation", "referenceRange", "device"]):
                return "Observation-related";

            // Categoria 6: Descritivos - Permite entender o conteúdo e a relevância dos dados.
            default:
                return "Descriptive";
        }
    }
}
