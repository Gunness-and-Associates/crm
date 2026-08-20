<?php

use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes a newsletter subscriber', function () {
    $subscriber = NewsletterSubscriber::factory()->create(['first_name' => 'Amina']);

    expect(NewsletterSubscriber::find($subscriber->id))->not->toBeNull();

    $subscriber->update(['source' => 'referral']);
    expect($subscriber->fresh()->source)->toBe('referral');

    $subscriber->delete();
    expect(NewsletterSubscriber::find($subscriber->id))->toBeNull()
        ->and(NewsletterSubscriber::withTrashed()->find($subscriber->id))->not->toBeNull();
});

it('unsubscribes and records the reason', function () {
    $subscriber = NewsletterSubscriber::factory()->create();

    $subscriber->unsubscribe('no longer interested');

    expect($subscriber->fresh())
        ->status->toBe('unsubscribed')
        ->unsubscribe_reason->toBe('no longer interested')
        ->opted_out_at->not->toBeNull();
});

it('relates a subscriber to its assigned user', function () {
    $user = User::factory()->create();
    $subscriber = NewsletterSubscriber::factory()->create(['assigned_user_id' => $user->id]);

    expect($subscriber->assignedUser->is($user))->toBeTrue();
});

it('scopes newsletter subscribers to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'newsletter_subscribers', AccessLevel::Owner);

    NewsletterSubscriber::factory()->create(['assigned_user_id' => $owner->id]);
    NewsletterSubscriber::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(NewsletterSubscriber::query()->count())->toBe(1);
});
