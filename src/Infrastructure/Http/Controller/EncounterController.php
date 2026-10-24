<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\ResolveEncounter;
use App\Application\Expedition\StartEncounterBattle;
use App\Application\Expedition\TradeWithMerchant;
use App\Domain\Expedition\EncounterDecision;
use App\Domain\Expedition\Resources;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Spotkania na morzu (#141): decyzja przy handlarzu albo piracie, który
 * zatrzymał flotę w rejsie. Stan spotkania i skutki wyborów daje GET /expedition.
 */
#[Route('/api/profiles/{id}/encounter')]
final class EncounterController
{
    public function __construct(
        private ResolveEncounter $resolveEncounter,
        private TradeWithMerchant $tradeWithMerchant,
        private StartEncounterBattle $startEncounterBattle,
    ) {
    }

    /** {decision: pass|flee|ransom}: minięcie handlarza, ucieczka albo okup u pirata. */
    #[Route('/decide', name: 'api_profiles_encounter_decide', methods: ['POST'])]
    public function decide(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id);
        $decision = EncounterDecision::tryFrom((string) ($this->jsonPayload($request)['decision'] ?? ''));
        if (null === $decision) {
            throw new ApiException(sprintf('Missing or invalid field: decision:%s', implode('|', array_column(EncounterDecision::cases(), 'value'))), 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->resolveEncounter->handle($id, $decision));
    }

    /** {sell: {wood, steel}, buy: {wood, steel}}: jedno zlecenie u handlarza za złoto z ładowni. */
    #[Route('/trade', name: 'api_profiles_encounter_trade', methods: ['POST'])]
    public function trade(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id);
        $payload = $this->jsonPayload($request);

        return new JsonResponse($this->tradeWithMerchant->handle($id, $this->order($payload, 'sell'), $this->order($payload, 'buy')));
    }

    /** {shipIds?}: napad na handlarza albo walka z piratem; kształt odpowiedzi jak POST /api/games. */
    #[Route('/battle', name: 'api_profiles_encounter_battle', methods: ['POST'])]
    public function battle(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id);
        $shipIds = $this->jsonPayload($request)['shipIds'] ?? null;
        if (null !== $shipIds && (!is_array($shipIds) || !array_is_list($shipIds) || [] !== array_filter($shipIds, static fn ($s) => !is_string($s)))) {
            throw new ApiException('Invalid field: shipIds must be a list of ship ids', 'VALIDATION_ERROR', 400);
        }

        $game = $this->startEncounterBattle->handle($id, $shipIds);

        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value,
            'ruleset' => $game->ruleset()->name(),
            'board' => [
                'w' => $game->ruleset()->boardSize()->width,
                'h' => $game->ruleset()->boardSize()->height,
            ],
        ], 201);
    }

    /** @param array<string,mixed> $payload */
    private function order(array $payload, string $side): Resources
    {
        $order = $payload[$side] ?? [];
        if (!is_array($order)) {
            throw new ApiException(sprintf('Invalid field: %s must be {wood, steel}', $side), 'VALIDATION_ERROR', 400);
        }
        $amounts = [];
        foreach (['wood', 'steel'] as $key) {
            $value = $order[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw new ApiException(sprintf('Invalid field: %s.wood/steel must be non-negative int', $side), 'VALIDATION_ERROR', 400);
            }
            $amounts[$key] = $value;
        }

        return Resources::fromArray($amounts);
    }

    /** @return array<string,mixed> */
    private function jsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            throw new ApiException('Invalid JSON', 'VALIDATION_ERROR', 400);
        }

        return $payload;
    }

    private function assertUuid(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid profile id', 'VALIDATION_ERROR', 400);
        }
    }
}
