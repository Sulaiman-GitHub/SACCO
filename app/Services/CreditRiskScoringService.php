<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreditRiskScoringService
{
    /**
     * Create a new scoring service instance.
     */
    public function __construct(
        protected CreditRiskFeatureExtractor $featureExtractor
    ) {}

    /**
     * Score a SACCO member for a proposed loan principal amount.
     *
     * @return array{
     *     success: bool,
     *     risk_score: float,
     *     default_probability: float,
     *     probability_calibrated: bool,
     *     risk_band: string,
     *     model_version: string,
     *     champion_algorithm: string,
     *     features: array{
     *         contribution_frequency: float,
     *         contribution_consistency: float,
     *         savings_balance: float,
     *         tenure_months: int,
     *         past_loans_count: int,
     *         past_default_count: int,
     *         on_time_repayment_rate: float,
     *         loan_to_savings_ratio: float
     *     },
     *     feature_contributions: array<string, float>,
     *     error: ?string
     * }
     */
    public function scoreMember(Member $member, float $requestedPrincipal): array
    {
        $features = $this->featureExtractor->extractFeatures($member, $requestedPrincipal);

        return $this->scoreFeatures($features);
    }

    /**
     * Send feature vector directly to the ML scoring microservice.
     *
     * @param array{
     *     contribution_frequency: float,
     *     contribution_consistency: float,
     *     savings_balance: float,
     *     tenure_months: int,
     *     past_loans_count: int,
     *     past_default_count: int,
     *     on_time_repayment_rate: float,
     *     loan_to_savings_ratio: float
     * } $features
     * @return array{
     *     success: bool,
     *     risk_score: float,
     *     default_probability: float,
     *     probability_calibrated: bool,
     *     risk_band: string,
     *     model_version: string,
     *     champion_algorithm: string,
     *     features: array{
     *         contribution_frequency: float,
     *         contribution_consistency: float,
     *         savings_balance: float,
     *         tenure_months: int,
     *         past_loans_count: int,
     *         past_default_count: int,
     *         on_time_repayment_rate: float,
     *         loan_to_savings_ratio: float
     *     },
     *     feature_contributions: array<string, float>,
     *     error: ?string
     * }
     */
    public function scoreFeatures(array $features): array
    {
        $baseUrl = rtrim((string) Config::get('services.ml_scoring.url', 'http://127.0.0.1:8001'), '/');
        $timeout = (int) Config::get('services.ml_scoring.timeout', 5);

        try {
            $response = Http::timeout($timeout)
                ->asJson()
                ->acceptJson()
                ->post("{$baseUrl}/score", $features);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'risk_score' => (float) ($data['risk_score'] ?? 0.0),
                    'default_probability' => (float) ($data['default_probability'] ?? 0.0),
                    'probability_calibrated' => (bool) ($data['probability_calibrated'] ?? false),
                    'risk_band' => (string) ($data['risk_band'] ?? 'unknown'),
                    'model_version' => (string) ($data['model_version'] ?? '1.0.0'),
                    'champion_algorithm' => (string) ($data['champion_algorithm'] ?? 'unknown'),
                    'features' => $features,
                    'feature_contributions' => (array) ($data['feature_contributions'] ?? []),
                    'error' => null,
                ];
            }

            Log::error('ML Risk Scoring Service error response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'features' => $features,
            ]);

            return $this->fallbackRiskAssessment($features, "Scoring service error ({$response->status()}): {$response->body()}");
        } catch (ConnectionException $e) {
            Log::warning('ML Risk Scoring Service connection failed, engaging rule fallback', [
                'error' => $e->getMessage(),
                'url' => "{$baseUrl}/score",
            ]);

            return $this->fallbackRiskAssessment($features, 'Scoring service is currently unreachable.');
        } catch (\Throwable $e) {
            Log::error('ML Risk Scoring Service unexpected exception', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackRiskAssessment($features, "Scoring error: {$e->getMessage()}");
        }
    }

    /**
     * Check if the ML scoring service is up and healthy.
     *
     * @return array{healthy: bool, details: array<string, mixed>}
     */
    public function healthCheck(): array
    {
        $baseUrl = rtrim((string) Config::get('services.ml_scoring.url', 'http://127.0.0.1:8001'), '/');
        $timeout = (int) Config::get('services.ml_scoring.timeout', 3);

        try {
            $response = Http::timeout($timeout)->acceptJson()->get("{$baseUrl}/health");

            if ($response->successful()) {
                return [
                    'healthy' => true,
                    'details' => $response->json() ?? [],
                ];
            }

            return [
                'healthy' => false,
                'details' => ['status_code' => $response->status()],
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Retrieve trained model metadata and comparative training evaluation metrics.
     *
     * @return array<string, mixed>
     */
    public function getModelInfo(): array
    {
        $baseUrl = rtrim((string) Config::get('services.ml_scoring.url', 'http://127.0.0.1:8001'), '/');
        $timeout = (int) Config::get('services.ml_scoring.timeout', 4);

        try {
            $response = Http::timeout($timeout)->acceptJson()->get("{$baseUrl}/model-info");

            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch ML model info', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Transparent deterministic fallback risk scoring when the Python microservice is offline.
     * Ensures SACCO loan operations never fail catastrophically if external services are down.
     *
     * @param array{
     *     contribution_frequency: float,
     *     contribution_consistency: float,
     *     savings_balance: float,
     *     tenure_months: int,
     *     past_loans_count: int,
     *     past_default_count: int,
     *     on_time_repayment_rate: float,
     *     loan_to_savings_ratio: float
     * } $features
     * @return array{
     *     success: bool,
     *     risk_score: float,
     *     default_probability: float,
     *     probability_calibrated: bool,
     *     risk_band: string,
     *     model_version: string,
     *     champion_algorithm: string,
     *     features: array{
     *         contribution_frequency: float,
     *         contribution_consistency: float,
     *         savings_balance: float,
     *         tenure_months: int,
     *         past_loans_count: int,
     *         past_default_count: int,
     *         on_time_repayment_rate: float,
     *         loan_to_savings_ratio: float
     *     },
     *     feature_contributions: array<string, float>,
     *     error: ?string
     * }
     */
    protected function fallbackRiskAssessment(array $features, string $errorMessage): array
    {
        // Heuristic fallback calculation. Each rule records the points it adds, so the reported
        // contributions are exactly what produced the score.
        $baseScore = 25.0;
        $points = [];

        if ($features['past_default_count'] > 0) {
            $points['past_default_count'] = 45.0 * $features['past_default_count'];
        }

        if ($features['loan_to_savings_ratio'] > 3.0) {
            $points['loan_to_savings_ratio'] = min(30.0, ($features['loan_to_savings_ratio'] - 3.0) * 10.0);
        }

        if ($features['on_time_repayment_rate'] < 0.8) {
            $points['on_time_repayment_rate'] = (0.8 - $features['on_time_repayment_rate']) * 35.0;
        }

        if ($features['tenure_months'] < 6) {
            $points['tenure_months'] = 15.0;
        }

        if ($features['contribution_frequency'] >= 2.0 && $features['past_default_count'] === 0) {
            $points['contribution_frequency'] = -10.0;
        }

        $score = $baseScore + array_sum($points);
        $boundedScore = min(100.0, max(0.0, round($score, 2)));
        $probability = round($boundedScore / 100.0, 4);

        $riskBand = match (true) {
            $boundedScore < 35.0 => 'low',
            $boundedScore < 65.0 => 'medium',
            default => 'high',
        };

        return [
            'success' => false,
            'risk_score' => $boundedScore,
            'default_probability' => $probability,
            'risk_band' => $riskBand,
            'model_version' => 'fallback-heuristic-1.0',
            'champion_algorithm' => 'heuristic_fallback',
            'features' => $features,
            'probability_calibrated' => false,
            'feature_contributions' => array_map(fn (float $value) => round($value, 2), $points),
            'error' => $errorMessage,
        ];
    }
}
