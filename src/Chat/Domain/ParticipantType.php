<?php

declare(strict_types=1);

namespace App\Chat\Domain;

/**
 * Discriminator for conversation ownership. Prevents KF admin id N from colliding with Order58 agent id N.
 */
enum ParticipantType: string
{
    case Admin = 'admin';
    case Agent = 'agent';
}
