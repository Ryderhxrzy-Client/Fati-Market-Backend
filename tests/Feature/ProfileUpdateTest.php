<?php

namespace Tests\Feature;

use App\Models\StudentInformation;
use PHPUnit\Framework\Attributes\Test;

/**
 * Changing the name on your own account.
 *
 * Names live on `student_information`, and until now nothing could write them
 * after registration: a name typed wrong once was carried for good, on every
 * screen and beside every line of the activity feed.
 */
class ProfileUpdateTest extends MarketplaceTestCase
{
    #[Test]
    public function an_admin_can_correct_their_own_name(): void
    {
        $admin = $this->admin();
        StudentInformation::create([
            'user_id' => $admin->user_id,
            'first_name' => 'Ofelai',
            'last_name' => 'Stroe',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/profile', ['first_name' => 'Ofelia', 'last_name' => 'Store'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ofelia')
            ->assertJsonPath('data.last_name', 'Store')
            ->assertJsonPath('data.user_id', $admin->user_id);

        $this->assertDatabaseHas('student_information', [
            'user_id' => $admin->user_id,
            'first_name' => 'Ofelia',
            'last_name' => 'Store',
        ]);
    }

    #[Test]
    public function an_account_with_no_name_row_gets_one(): void
    {
        // A Google sign-up may never have made one.
        $student = $this->student();
        $this->assertNull($student->studentInfo);

        $this->actingAs($student)
            ->putJson('/api/profile', ['first_name' => 'Juan', 'last_name' => 'Dela Cruz'])
            ->assertOk();

        $this->assertSame('Juan', $student->fresh()->studentInfo?->first_name);
    }

    #[Test]
    public function the_photo_already_on_file_is_left_alone(): void
    {
        $admin = $this->admin();
        StudentInformation::create([
            'user_id' => $admin->user_id,
            'first_name' => 'Ofelia',
            'last_name' => 'Store',
            'profile_picture' => 'https://cdn.test/ofelia.jpg',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/profile', ['first_name' => 'Ofelia', 'last_name' => 'Santos'])
            ->assertOk()
            ->assertJsonPath('data.profile_picture', 'https://cdn.test/ofelia.jpg');
    }

    #[Test]
    public function a_blank_name_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/profile', ['first_name' => '', 'last_name' => 'Store'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('first_name');

        $this->actingAs($admin)
            ->putJson('/api/profile', ['first_name' => 'Ofelia'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('last_name');
    }

    #[Test]
    public function only_your_own_name_and_only_when_signed_in(): void
    {
        $this->putJson('/api/profile', ['first_name' => 'Someone', 'last_name' => 'Else'])
            ->assertStatus(401);
    }
}
