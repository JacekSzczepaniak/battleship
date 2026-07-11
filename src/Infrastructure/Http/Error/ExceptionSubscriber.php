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
        if ('Torpedo must be launched from an unsunk ship' === $message) {
            return ['TORPEDO_LAUNCH_INVALID', 422];
        }

        // Wyprawa: wyspa zamknięta rangą (komunikat niesie wymaganą rangę)
        if (str_starts_with($message, 'Island locked')) {
            return ['ISLAND_LOCKED', 403];
        }

        return match ($message) {
            'Fleet not placed' => ['FLEET_NOT_PLACED', 422],
            'Opponent fleet not placed' => ['FLEET_NOT_PLACED', 422],
            'Invalid fleet composition' => ['VALIDATION_ERROR', 400],
            'Fleet already placed' => ['VALIDATION_ERROR', 409],
            'Not player turn' => ['NOT_PLAYER_TURN', 409],
            'Game already finished' => ['GAME_ALREADY_FINISHED', 409],
            'Profile not found' => ['PROFILE_NOT_FOUND', 404],
            'Island not found' => ['ISLAND_NOT_FOUND', 404],
            'Battle not registered for this profile' => ['BATTLE_NOT_REGISTERED', 404],
            'Battle not finished yet' => ['BATTLE_NOT_FINISHED', 409],
            default => ['VALIDATION_ERROR', 400],
        };
    }
}
