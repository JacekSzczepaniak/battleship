<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\BuildBuilding;
use App\Application\Expedition\BuildShip;
use App\Application\Expedition\CreateProfile;
use App\Application\Expedition\DecidePrize;
use App\Application\Expedition\GetExpedition;
use App\Application\Expedition\PassGate;
use App\Application\Expedition\RepairShip;
use App\Application\Expedition\Sail;
use App\Application\Expedition\ScrapShip;
use App\Application\Expedition\SettleBattle;
use App\Application\Expedition\StartGuardianBattle;
use App\Application\Expedition\StartIslandBattle;
use App\Application\Expedition\StartResearch;
use App\Application\Expedition\StartSiege;
use App\Application\Expedition\TransferCargo;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\PrizeDecision;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Game;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/profiles')]
final class ExpeditionController
{
    public function __construct(
        private CreateProfile $createProfile,
        private GetExpedition $getExpedition,
        private StartIslandBattle $startIslandBattle,
        private SettleBattle $settleBattle,
        private BuildShip $buildShip,
        private RepairShip $repairShip,
        private DecidePrize $decidePrize,
        private Sail $sail,
        private TransferCargo $transferCargo,
        private BuildBuilding $buildBuilding,
        private StartResearch $startResearch,
        private StartGuardianBattle $startGuardianBattle,
        private PassGate $passGate,
        private StartSiege $startSiege,
        private ScrapShip $scrapShip,
    ) {
    }

    #[Route('', name: 'api_profiles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            throw new ApiException('Invalid JSON', 'VALIDATION_ERROR', 400);
        }

        $name = $payload['name'] ?? null;
        if (null !== $name && !is_string($name)) {
            throw new ApiException('Invalid field: name:string', 'VALIDATION_ERROR', 400);
        }

        $profile = $this->createProfile->handle($name);

        return new JsonResponse([
            'id' => (string) $profile->id(),
            'name' => $profile->name(),
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
        ], 201);
    }

    #[Route('/{id}/expedition', name: 'api_profiles_expedition', methods: ['GET'])]
    public function expedition(string $id): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return new JsonResponse($this->getExpedition->handle($id));
    }

    #[Route('/{id}/islands/{islandId}/battle', name: 'api_profiles_island_battle', methods: ['POST'])]
    public function startBattle(string $id, string $islandId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return $this->gameCreated($this->startIslandBattle->handle($id, $islandId, $this->shipIds($request)));
    }

    #[Route('/{id}/islands/{islandId}/siege', name: 'api_profiles_island_siege', methods: ['POST'])]
    public function startSiege(string $id, string $islandId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return $this->gameCreated($this->startSiege->handle($id, $islandId, $this->shipIds($request)));
    }

    #[Route('/{id}/gates/{gateId}/battle', name: 'api_profiles_guardian_battle', methods: ['POST'])]
    public function startGuardianBattle(string $id, string $gateId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return $this->gameCreated($this->startGuardianBattle->handle($id, $gateId, $this->shipIds($request)));
    }

    #[Route('/{id}/gates/{gateId}/pass', name: 'api_profiles_pass_gate', methods: ['POST'])]
    public function passGate(string $id, string $gateId): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return new JsonResponse($this->passGate->handle($id, $gateId));
    }

    #[Route('/{id}/battles/{gameId}/settle', name: 'api_profiles_battle_settle', methods: ['POST'])]
    public function settle(string $id, string $gameId): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $this->assertUuid($gameId, 'game');

        return new JsonResponse($this->settleBattle->handle($id, $gameId));
    }

    #[Route('/{id}/sail', name: 'api_profiles_sail', methods: ['POST'])]
    public function sail(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        if (!is_int($x) || !is_int($y)) {
            throw new ApiException('Missing or invalid fields: x:int, y:int', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->sail->handle($id, $x, $y));
    }

    #[Route('/{id}/ships', name: 'api_profiles_build_ship', methods: ['POST'])]
    public function buildShip(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $type = ShipType::tryFrom((string) ($payload['type'] ?? ''));
        $islandId = $payload['islandId'] ?? null;
        if (null === $type || !is_string($islandId)) {
            throw new ApiException('Missing or invalid fields: type:ship-type, islandId:string', 'VALIDATION_ERROR', 400);
        }
        // wariant opcjonalny: bez niego statek dostaje domyślny (#99)
        $variant = null;
        if (isset($payload['variant'])) {
            $variant = ShipVariant::tryFrom((string) $payload['variant'])
                ?? throw new ApiException('Invalid field: variant:ship-variant', 'VALIDATION_ERROR', 400);
        }

        $ship = $this->buildShip->handle($id, $islandId, $type, $variant);

        return new JsonResponse($this->shipView($ship), 201);
    }

    #[Route('/{id}/ships/{shipId}/repair', name: 'api_profiles_repair_ship', methods: ['POST'])]
    public function repairShip(string $id, string $shipId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $islandId = $payload['islandId'] ?? null;
        if (!is_string($islandId)) {
            throw new ApiException('Missing or invalid field: islandId:string', 'VALIDATION_ERROR', 400);
        }

        $ship = $this->repairShip->handle($id, $islandId, $shipId);

        return new JsonResponse($this->shipView($ship));
    }

    /** Złomowanie statku w stoczni portu (#124): zwrot trafia do magazynu wyspy. */
    #[Route('/{id}/ships/{shipId}/scrap', name: 'api_profiles_scrap_ship', methods: ['POST'])]
    public function scrapShip(string $id, string $shipId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $islandId = $payload['islandId'] ?? null;
        if (!is_string($islandId)) {
            throw new ApiException('Missing or invalid field: islandId:string', 'VALIDATION_ERROR', 400);
        }

        $outcome = $this->scrapShip->handle($id, $islandId, $shipId);

        return new JsonResponse([
            'refund' => $outcome['refund']->toArray(),
            'stockpile' => $outcome['stockpile']->toArray(),
            'cargo' => $outcome['cargo']->toArray(),
        ]);
    }

    /** Decyzja o statku zdobytym abordażem (#118): {decision: join|scrap|study}; study = zbadaj konstrukcję (#121). */
    #[Route('/{id}/prizes/{prizeId}', name: 'api_profiles_decide_prize', methods: ['POST'])]
    public function decidePrize(string $id, string $prizeId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $decision = PrizeDecision::tryFrom((string) ($this->jsonPayload($request)['decision'] ?? ''));
        if (null === $decision) {
            throw new ApiException(sprintf('Missing or invalid field: decision:%s', implode('|', array_column(PrizeDecision::cases(), 'value'))), 'VALIDATION_ERROR', 400);
        }

        $outcome = $this->decidePrize->handle($id, $prizeId, $decision);

        return new JsonResponse([
            'decision' => $outcome['decision'],
            'ship' => null !== $outcome['ship'] ? $this->shipView($outcome['ship']) : null,
            'refund' => $outcome['refund']->toArray(),
            'cargoLost' => $outcome['cargoLost']->toArray(),
            'cargo' => $outcome['cargo']->toArray(),
            'technology' => $outcome['technology']?->value,
        ]);
    }

    #[Route('/{id}/islands/{islandId}/buildings', name: 'api_profiles_build_building', methods: ['POST'])]
    public function buildBuilding(string $id, string $islandId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        $type = BuildingType::tryFrom((string) ($this->jsonPayload($request)['type'] ?? ''));
        if (null === $type) {
            throw new ApiException('Missing or invalid field: type:building-type', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->buildBuilding->handle($id, $islandId, $type), 201);
    }

    #[Route('/{id}/islands/{islandId}/research', name: 'api_profiles_start_research', methods: ['POST'])]
    public function startResearch(string $id, string $islandId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        $technology = Technology::tryFrom((string) ($this->jsonPayload($request)['technology'] ?? ''));
        if (null === $technology) {
            throw new ApiException('Missing or invalid field: technology:technology', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->startResearch->handle($id, $islandId, $technology), 201);
    }

    #[Route('/{id}/cargo', name: 'api_profiles_transfer_cargo', methods: ['POST'])]
    public function transferCargo(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $islandId = $payload['islandId'] ?? null;
        $direction = $payload['direction'] ?? null;
        $amounts = [];
        foreach (['wood', 'steel', 'gold'] as $key) {
            $value = $payload[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw new ApiException('Invalid field: wood/steel/gold must be non-negative int', 'VALIDATION_ERROR', 400);
            }
            $amounts[$key] = $value;
        }
        if (!is_string($islandId) || !in_array($direction, ['load', 'unload'], true)) {
            throw new ApiException('Missing or invalid fields: islandId:string, direction:load|unload', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->transferCargo->handle($id, $islandId, $direction, Resources::fromArray($amounts)));
    }

    /**
     * Opcjonalny skład bitewny; brak = dobór domyślny w limicie pól planszy.
     *
     * @return list<string>|null
     */
    private function shipIds(Request $request): ?array
    {
        $shipIds = $this->jsonPayload($request)['shipIds'] ?? null;
        if (null !== $shipIds && (!is_array($shipIds) || !array_is_list($shipIds) || [] !== array_filter($shipIds, static fn ($s) => !is_string($s)))) {
            throw new ApiException('Invalid field: shipIds must be a list of ship ids', 'VALIDATION_ERROR', 400);
        }

        return $shipIds;
    }

    /** Ten sam kształt co POST /api/games — frontend wchodzi w zwykły flow gry. */
    private function gameCreated(Game $game): JsonResponse
    {
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

    /** @return array<string,mixed> */
    private function shipView(OwnedShip $ship): array
    {
        return [
            'id' => $ship->id,
            'type' => $ship->type->value,
            'length' => $ship->type->length(),
            'variant' => $ship->variant?->value,
            'damaged' => $ship->isDamaged(),
        ];
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

    private function assertUuid(string $id, string $what): void
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException(sprintf('Invalid %s id', $what), 'VALIDATION_ERROR', 400);
        }
    }
}
