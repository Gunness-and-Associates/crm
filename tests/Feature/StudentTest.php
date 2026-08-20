<?php

use App\Models\Student;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes a student', function () {
    $student = Student::factory()->create(['first_name' => 'Amina']);

    expect(Student::find($student->id))->not->toBeNull();

    $student->update(['status' => 'enrolled']);
    expect($student->fresh()->status)->toBe('enrolled');

    $student->delete();
    expect(Student::find($student->id))->toBeNull()
        ->and(Student::withTrashed()->find($student->id))->not->toBeNull();
});

it('relates a student to its assigned user', function () {
    $user = User::factory()->create();
    $student = Student::factory()->create(['assigned_user_id' => $user->id]);

    expect($student->assignedUser->is($user))->toBeTrue();
});

it('scopes students to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'students', AccessLevel::Owner);

    Student::factory()->create(['assigned_user_id' => $owner->id]);
    Student::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Student::query()->count())->toBe(1);
});
