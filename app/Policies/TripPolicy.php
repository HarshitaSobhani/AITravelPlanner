<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TripPolicy
{
    public function view(User $user, Trip $trip): Response
    {
        return $this->owns($user, $trip);
    }

    public function update(User $user, Trip $trip): Response
    {
        return $this->owns($user, $trip);
    }

    public function delete(User $user, Trip $trip): Response
    {
        return $this->owns($user, $trip);
    }

    // Non-owners get a 404, so trip ids cannot be probed.
    private function owns(User $user, Trip $trip): Response
    {
        return $trip->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
