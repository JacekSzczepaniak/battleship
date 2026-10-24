<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Error;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        // ApiException — użyj danych z wyjątku
        if ($e instanceof ApiException) {
            $event->setResponse($this->jsonError($e->getMessage(), $e->apiCode(), $e->httpStatus()));

            return;
        }

        // DomainException — mapowanie komunikatów na kody
        if ($e instanceof \DomainException) {
            $message = $e->getMessage() ?: 'Domain error';
            [$code, $status] = $this->mapDomainMessage($message);
            $event->setResponse($this->jsonError($message, $code, $status));

            return;
        }

        // InvalidArgumentException — często "not found" lub walidacja
        if ($e instanceof \InvalidArgumentException) {
            $message = $e->getMessage() ?: 'Invalid argument';
            $norm = strtolower($message);
            if (str_contains($norm, 'not found')) {
                $event->setResponse($this->jsonError('Game not found', 'GAME_NOT_FOUND', 404));

                return;
            }
            if (str_contains($norm, 'invalid game id') || str_contains($norm, 'uuid')) {
                $event->setResponse($this->jsonError('Invalid game id', 'INVALID_GAME_ID', 400));

                return;
            }

            $event->setResponse($this->jsonError($message, 'VALIDATION_ERROR', 400));

            return;
        }

        // Inne wyjątki — INTERNAL_ERROR (ogólny komunikat dla klienta,
        // szczegóły do stderr/php-fpm, żeby 500-tki nie znikały bez śladu)
        error_log(sprintf(
            'INTERNAL_ERROR: %s: %s @ %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        $event->setResponse($this->jsonError('Internal server error', 'INTERNAL_ERROR', 500));
    }

    private function jsonError(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['message' => $message, 'code' => $code]], $status);
    }

    /** @return array{0:string,1:int} [apiCode, httpStatus] */
    private function mapDomainMessage(string $message): array
    {
        // Bronie specjalne: komunikat zaczyna się od nazwy broni (Torpedo/Sonar/AirRaid …)
        if (str_ends_with($message, 'not available in this ruleset')) {
            return ['WEAPON_NOT_AVAILABLE', 422];
        }
        if (str_ends_with($message, 'limit reached')) {
            return ['WEAPON_LIMIT_REACHED', 422];
        }
        if (str_starts_with($message, 'Torpedo must be launched')) {
            return ['TORPEDO_LAUNCH_INVALID', 422];
        }
        // Zatopiony nośnik broni (kuter/niszczyciel/lotniskowiec)
        if ('Sonar requires an unsunk scout ship' === $message || 'Air raid requires an unsunk carrier' === $message) {
            return ['WEAPON_CARRIER_SUNK', 422];
        }

        // Wyprawa: bramki rangowe (komunikaty niosą wymaganą rangę)
        if (str_starts_with($message, 'Island locked')) {
            return ['ISLAND_LOCKED', 403];
        }
        if (str_starts_with($message, 'Ship type locked')) {
            return ['SHIP_TYPE_LOCKED', 403];
        }
        if (str_starts_with($message, 'Ship variant locked')) {
            return ['SHIP_VARIANT_LOCKED', 403];
        }
        if (str_starts_with($message, 'Technology locked')) {
            return ['TECHNOLOGY_LOCKED', 403];
        }
        if (str_starts_with($message, 'Gate locked')) {
            return ['GATE_LOCKED', 403];
        }

        return match ($message) {
            'Fleet not placed' => ['FLEET_NOT_PLACED', 422],
            'Opponent fleet not placed' => ['FLEET_NOT_PLACED', 422],
            'Invalid fleet composition' => ['VALIDATION_ERROR', 400],
            'Fleet already placed' => ['VALIDATION_ERROR', 409],
            'Not player turn' => ['NOT_PLAYER_TURN', 409],
            // energia w bitwie (#59)
            'Not enough energy' => ['NOT_ENOUGH_ENERGY', 409],
            'End turn requires battle energy' => ['END_TURN_NOT_AVAILABLE', 422],
            // ruch statku w bitwie (#60)
            'Ship move not available' => ['SHIP_MOVE_NOT_AVAILABLE', 422],
            'Ship already moved this turn' => ['SHIP_ALREADY_MOVED', 409],
            'No own ship at this cell' => ['SHIP_MOVE_NO_SHIP', 422],
            'Damaged ship cannot move' => ['SHIP_MOVE_DAMAGED', 422],
            'Ship can only move along its axis' => ['SHIP_MOVE_DIRECTION', 422],
            'Ship move out of board bounds' => ['SHIP_MOVE_OUT_OF_BOUNDS', 422],
            'Ship cannot move onto a cell already shot' => ['SHIP_MOVE_SHOT_CELL', 422],
            'Ship move would touch another ship' => ['SHIP_MOVE_TOUCH', 422],
            // abordaż (#118)
            'Boarding not available' => ['BOARDING_NOT_AVAILABLE', 422],
            'Boarding requires the last enemy ship' => ['BOARDING_NOT_LAST_SHIP', 422],
            'Boarding already attempted' => ['BOARDING_ALREADY_ATTEMPTED', 409],
            // zdobyty statek (#118)
            'Prize not found' => ['PRIZE_NOT_FOUND', 404],
            // zbadanie konstrukcji (#121)
            'Prize cannot be studied' => ['PRIZE_NOT_STUDIABLE', 409],
            'Technology available only from boarded ships' => ['TECHNOLOGY_BOARDING_ONLY', 409],
            'Game already finished' => ['GAME_ALREADY_FINISHED', 409],
            'Profile not found' => ['PROFILE_NOT_FOUND', 404],
            'Island not found' => ['ISLAND_NOT_FOUND', 404],
            'Battle not registered for this profile' => ['BATTLE_NOT_REGISTERED', 404],
            'Battle not finished yet' => ['BATTLE_NOT_FINISHED', 409],
            'Not enough resources' => ['NOT_ENOUGH_RESOURCES', 409],
            'Cargo capacity exceeded' => ['CARGO_FULL', 409],
            'Squad exceeds island limit' => ['SQUAD_TOO_LARGE', 409],
            'Ship not seaworthy' => ['SHIP_NOT_SEAWORTHY', 409],
            'Convoy needs an escort' => ['CONVOY_UNESCORTED', 409],
            'Not at island' => ['NOT_AT_ISLAND', 409],
            'Sector out of bounds' => ['VALIDATION_ERROR', 400],
            'Can only sail to an adjacent sector' => ['SAIL_NOT_ADJACENT', 409],
            'Sector beyond sailing range' => ['SAIL_OUT_OF_RANGE', 409],
            'Shipyard level too low for this ship type' => ['SHIPYARD_TOO_LOW', 409],
            'Ship not found' => ['SHIP_NOT_FOUND', 404],
            'Ship is not damaged' => ['SHIP_NOT_DAMAGED', 409],
            'No shipyard on this island' => ['NO_SHIPYARD', 409],
            'Ship in an unsettled battle' => ['SHIP_IN_BATTLE', 409],
            'Cannot scrap the last seaworthy ship' => ['LAST_SEAWORTHY_SHIP', 409],
            'No seaworthy ships' => ['NO_SEAWORTHY_SHIPS', 409],
            'Island not owned' => ['ISLAND_NOT_OWNED', 409],
            'Building already standing' => ['BUILDING_ALREADY_STANDING', 409],
            'Building site not suitable' => ['BUILDING_SITE_UNSUITABLE', 409],
            'Island already captured' => ['ISLAND_ALREADY_CAPTURED', 409],
            // twierdze z fortem (#120)
            'Island already besieged' => ['ISLAND_ALREADY_BESIEGED', 409],
            'Island not besieged' => ['ISLAND_NOT_BESIEGED', 409],
            'Island has no fort' => ['ISLAND_HAS_NO_FORT', 409],
            'No counterattack on this island' => ['NO_COUNTERATTACK', 409],
            'Defense already started' => ['DEFENSE_ALREADY_STARTED', 409],
            'Technology already researched' => ['TECHNOLOGY_RESEARCHED', 409],
            'Research already in progress' => ['RESEARCH_IN_PROGRESS', 409],
            'Technology requires previous level in branch' => ['TECHNOLOGY_PREREQUISITE', 409],
            'No active academy on this island' => ['NO_ACADEMY', 409],
            // bramy akwenów (#107)
            'Gate not found' => ['GATE_NOT_FOUND', 404],
            'Gate has no guardian' => ['GATE_HAS_NO_GUARDIAN', 409],
            'Gate already open' => ['GATE_ALREADY_OPEN', 409],
            'Gate guarded: defeat the guardian first' => ['GATE_GUARDED', 409],
            'Not at gate' => ['NOT_AT_GATE', 409],
            // port handlowy i rynek (#140)
            'Trade port not found' => ['TRADE_PORT_NOT_FOUND', 404],
            'Not at trade port' => ['NOT_AT_TRADE_PORT', 409],
            // zestawy floty (#125)
            'Fleet preset not found' => ['FLEET_PRESET_NOT_FOUND', 404],
            'Fleet preset limit exceeded' => ['FLEET_PRESET_LIMIT', 409],
            'Fleet preset name taken' => ['FLEET_PRESET_NAME_TAKEN', 409],
            default => ['VALIDATION_ERROR', 400],
        };
    }
}
