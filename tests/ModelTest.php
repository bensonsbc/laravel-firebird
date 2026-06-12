<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Tests\Support\MigrateDatabase;
use Benson\LaravelFirebird\Tests\Support\Models\Order;
use Benson\LaravelFirebird\Tests\Support\Models\User;
use PHPUnit\Framework\Attributes\Test;

class ModelTest extends TestCase
{
    use MigrateDatabase;

    #[Test]
    public function it_can_create_a_record()
    {
        $user = User::create($fields = [
            'name' => 'Anna',
            'email' => 'anna@example.com',
            'city' => 'Sydney',
            'state' => 'New South Wales',
            'post_code' => '2000',
            'country' => 'Australia',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertInstanceOf(User::class, $user);

        $this->assertDatabaseHas('users', $fields);

        $foundUser = User::find($user->id);

        $this->assertTrue($user->is($foundUser));

        $this->assertInstanceOf(User::class, $foundUser);

        // Check all fields have been persisted the model.
        foreach ($fields as $key => $value) {
            $this->assertEquals($value, $foundUser->{$key});
        }
    }

    #[Test]
    public function it_can_load_basic_relationships()
    {
        $user = User::factory()->create([
            'name' => 'Relationship User',
        ]);

        Order::factory()->count(2)->create([
            'user_id' => $user->id,
            'name' => 'Related Order',
        ]);

        $loadedUser = User::with('orders')->find($user->id);

        $this->assertCount(2, $loadedUser->orders);
        $this->assertTrue($loadedUser->orders->every(
            fn (Order $order) => $order->user_id === $user->id
        ));

        $order = Order::with('user')->where('user_id', $user->id)->first();

        $this->assertTrue($user->is($order->user));
    }

    #[Test]
    public function it_can_first_or_create_models()
    {
        $user = User::firstOrCreate(
            ['email' => 'first-or-create@example.com'],
            [
                'name' => 'First Create',
                'city' => 'Sydney',
                'state' => 'New South Wales',
                'post_code' => '2000',
                'country' => 'Australia',
            ],
        );

        $sameUser = User::firstOrCreate(
            ['email' => 'first-or-create@example.com'],
            [
                'name' => 'Should Not Replace',
                'city' => 'Melbourne',
                'state' => 'Victoria',
                'post_code' => '3000',
                'country' => 'Australia',
            ],
        );

        $this->assertTrue($user->is($sameUser));
        $this->assertSame('First Create', $sameUser->name);
        $this->assertSame(1, User::where('email', 'first-or-create@example.com')->count());
    }

    #[Test]
    public function it_can_update_or_create_models()
    {
        $created = User::updateOrCreate(
            ['email' => 'update-or-create@example.com'],
            [
                'name' => 'Original Name',
                'city' => 'Sydney',
                'state' => 'New South Wales',
                'post_code' => '2000',
                'country' => 'Australia',
            ],
        );

        $updated = User::updateOrCreate(
            ['email' => 'update-or-create@example.com'],
            [
                'name' => 'Updated Name',
                'city' => 'Brisbane',
                'state' => 'Queensland',
                'post_code' => '4000',
                'country' => 'Australia',
            ],
        );

        $this->assertTrue($created->is($updated));
        $this->assertSame('Updated Name', $updated->name);
        $this->assertDatabaseHas('users', [
            'email' => 'update-or-create@example.com',
            'name' => 'Updated Name',
            'city' => 'Brisbane',
        ]);
        $this->assertSame(1, User::where('email', 'update-or-create@example.com')->count());
    }

    #[Test]
    public function it_can_create_many_related_models()
    {
        $user = User::factory()->create();

        $orders = $user->orders()->createMany([
            [
                'name' => 'First related order',
                'price' => 25,
                'quantity' => 2,
            ],
            [
                'name' => 'Second related order',
                'price' => 40,
                'quantity' => 1,
            ],
        ]);

        $this->assertCount(2, $orders);
        $this->assertSame(2, $user->orders()->count());
        $this->assertTrue($orders->every(
            fn (Order $order) => $order->user_id === $user->id
        ));
    }

    #[Test]
    public function it_can_soft_delete_models()
    {
        $user = User::factory()->create([
            'email' => 'soft-delete@example.com',
        ]);

        $user->delete();

        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertSame($user->id, User::onlyTrashed()->where('email', 'soft-delete@example.com')->first()->id);

        $user->restore();

        $this->assertNotNull(User::find($user->id));
        $this->assertNull(User::onlyTrashed()->where('email', 'soft-delete@example.com')->first());
    }
}
