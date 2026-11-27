<?php

declare(strict_types=1);

namespace App\Payment\UserInterface;

use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Model\TransactionListCriteria;
use App\Shared\Http\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

final class TransactionListQuery
{
    public function fromRequest(Request $request): TransactionListCriteria
    {
        $page = $this->integer($request, 'page', false) ?? 1;
        if ($page > 1000) {
            throw ApiProblemException::validation('La page ne peut pas dépasser 1000.', '/page');
        }
        $perPage = $this->integer($request, 'items_per_page', false) ?? 25;
        if ($perPage > 100) {
            throw ApiProblemException::validation('La page contient au plus 100 éléments.', '/items_per_page');
        }

        return new TransactionListCriteria(
            $page,
            $perPage,
            $this->status($request),
            $this->date($request, 'created_from', false),
            $this->date($request, 'created_to', true),
            $this->integer($request, 'amount_min', true),
            $this->integer($request, 'amount_max', true),
            $this->query($request),
        );
    }

    private function status(Request $request): ?TransactionStatus
    {
        if (!$request->query->has('status')) {
            return null;
        }
        $value = $request->query->get('status');
        if (!\is_string($value)) {
            throw ApiProblemException::validation('Statut invalide.', '/status');
        }
        $status = TransactionStatus::tryFrom($value);
        if (!$status instanceof TransactionStatus) {
            throw ApiProblemException::validation('Statut invalide.', '/status');
        }

        return $status;
    }

    private function query(Request $request): ?string
    {
        if (!$request->query->has('q')) {
            return null;
        }
        $value = $request->query->get('q');
        if (!\is_string($value)) {
            throw ApiProblemException::validation('Recherche invalide.', '/q');
        }
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > 100) {
            throw ApiProblemException::validation('La recherche contient au plus 100 caractères.', '/q');
        }

        return $value;
    }

    private function integer(Request $request, string $name, bool $allowZero): ?int
    {
        if (!$request->query->has($name)) {
            return null;
        }
        $value = $request->query->get($name);
        if (!\is_string($value) || 1 !== preg_match('/^[0-9]+$/', $value)) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$name);
        }
        $number = (int) $value;
        if ($number < ($allowZero ? 0 : 1)) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$name);
        }

        return $number;
    }

    private function date(Request $request, string $name, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!$request->query->has($name)) {
            return null;
        }
        $value = $request->query->get($name);
        if (!\is_string($value) || '' === $value) {
            throw ApiProblemException::validation('Date invalide.', '/'.$name);
        }

        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof \DateTimeImmutable && $this->dateIsClean()) {
                return $parsed;
            }
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day instanceof \DateTimeImmutable && $this->dateIsClean()) {
            return $endOfDay ? $day->setTime(23, 59, 59) : $day;
        }

        throw ApiProblemException::validation('Date invalide.', '/'.$name);
    }

    private function dateIsClean(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $errors) {
            return true;
        }

        return 0 === $errors['warning_count'] && 0 === $errors['error_count'];
    }
}
