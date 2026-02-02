<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Repository;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;

/**
 * Interfaz del repositorio de YoutubeAccount
 * 
 * Define los métodos para acceder y manipular cuentas de YouTube en la persistencia
 */
interface YoutubeAccountRepositoryInterface
{
    /**
     * Guarda una cuenta de YouTube
     */
    public function save(YoutubeAccount $account): void;

    /**
     * Encuentra una cuenta por ID
     */
    public function findOneById(string $id): ?YoutubeAccount;

    /**
     * Encuentra una cuenta por accountName
     */
    public function findOneByAccountName(string $accountName): ?YoutubeAccount;

    /**
     * Encuentra todas las cuentas
     * 
     * @return YoutubeAccount[]
     */
    public function findAll(): array;

    /**
     * Elimina una cuenta
     */
    public function delete(YoutubeAccount $account): void;
}
