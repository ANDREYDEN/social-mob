<?php

namespace Tests\Feature;

use App\SocialMob;
use App\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Tests\TestCase;

class SocialMobTest extends TestCase
{
    public function testAnAuthenticatedUserCanCreateASocialMob()
    {
        $user = factory(User::class)->create();
        $topic = 'The fundamentals of foo';
        $this->actingAs($user)->postJson(route('social_mobs.store'), [
            'topic' => $topic,
            'location' => 'At the central mobbing area',
            'start_time' => now()->format('h:i a'),
            'date' => today(),
        ])->assertSuccessful();

        $this->assertEquals($topic, $user->socialMobs->first()->topic);
    }

    public function testTheOwnerOfAMobCanEditIt()
    {
        $mob = factory(SocialMob::class)->create();
        $newTopic = 'A brand new topic!';

        $this->actingAs($mob->owner)->putJson(route('social_mobs.update', ['social_mob' => $mob->id]), [
            'topic' => $newTopic,
        ])->assertSuccessful();

        $this->assertEquals($newTopic, $mob->fresh()->topic);
    }

    public function testTheOwnerCanChangeTheDateOfAnUpcomingMob()
    {
        $this->setTestNow('2020-01-01');
        $mob = factory(SocialMob::class)->create(['date' => "2020-01-02"]);
        $newDate = '2020-01-10';

        $this->actingAs($mob->owner)->putJson(route('social_mobs.update', ['social_mob' => $mob->id]), [
            'date' => $newDate,
        ])->assertSuccessful();

        $this->assertEquals($newDate, $mob->fresh()->toArray()['date']);
    }

    public function testTheDateOfTheMobCannotBeSetToThePast()
    {
        $this->setTestNow('2020-01-05');
        $mob = factory(SocialMob::class)->create(['date' => "2020-01-06"]);
        $newDate = '2020-01-03';

        $this->actingAs($mob->owner)->putJson(route('social_mobs.update', ['social_mob' => $mob->id]), [
            'date' => $newDate,
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheOwnerCannotUpdateAMobThatAlreadyHappened()
    {
        $this->setTestNow('2020-01-05');
        $mob = factory(SocialMob::class)->create(['date' => "2020-01-01"]);
        $newDate = '2020-01-10';

        $this->actingAs($mob->owner)->putJson(route('social_mobs.update', ['social_mob' => $mob->id]), [
            'date' => $newDate,
        ])->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function testAUserThatIsNotAnOwnerOfAMobCannotEditIt()
    {
        $mob = factory(SocialMob::class)->create();
        $notTheOwner = factory(User::class)->create();

        $this->actingAs($notTheOwner)->putJson(route('social_mobs.update', ['social_mob' => $mob->id]), [
            'topic' => 'Anything'
        ])->assertForbidden();
    }

    public function testTheOwnerCanDeleteAnExistingMob()
    {
        $mob = factory(SocialMob::class)->create();

        $this->actingAs($mob->owner)->deleteJson(route('social_mobs.destroy', ['social_mob' => $mob->id]))
            ->assertSuccessful();

        $this->assertEmpty($mob->fresh());
    }

    public function testAUserThatIsNotAnOwnerOfAMobCannotDeleteIt()
    {
        $mob = factory(SocialMob::class)->create();
        $notTheOwner = factory(User::class)->create();

        $this->actingAs($notTheOwner)->deleteJson(route('social_mobs.destroy', ['social_mob' => $mob->id]))
            ->assertForbidden();
    }

    public function testAGivenUserCanRSVPToASocialMob()
    {
        $existingSocialMob = factory(SocialMob::class)->create();
        $user = factory(User::class)->create();

        $this->actingAs($user)
            ->postJson(route('social_mobs.join', ['social_mob' => $existingSocialMob->id]))
            ->assertSuccessful();

        $this->assertEquals($user->id, $existingSocialMob->attendees->first()->id);
    }

    public function testAUserCannotJoinTheSameMobTwice()
    {
        $existingSocialMob = factory(SocialMob::class)->create();
        $user = factory(User::class)->create();
        $existingSocialMob->attendees()->attach($user);

        $this->actingAs($user)
            ->postJson(route('social_mobs.join', ['social_mob' => $existingSocialMob->id]))
            ->assertForbidden();

        $this->assertCount(1, $existingSocialMob->attendees);
    }

    public function testAUserCanLeaveTheMob()
    {
        $existingSocialMob = factory(SocialMob::class)->create();
        $user = factory(User::class)->create();
        $existingSocialMob->attendees()->attach($user);


        $this->actingAs($user)
            ->postJson(route('social_mobs.leave', ['social_mob' => $existingSocialMob->id]))
            ->assertSuccessful();

        $this->assertEmpty($existingSocialMob->attendees);
    }

    public function testItCanProvideAllSocialMobsOfTheCurrentWeekForAuthenticatedUser()
    {
        $this->setTestNow('2020-01-15');
        $monday = CarbonImmutable::parse('Last Monday');

        $mondaySocial = factory(SocialMob::class)
            ->create(['date' => $monday, 'start_time' => '03:30 pm'])
            ->toArray();
        $lateWednesdaySocial = factory(SocialMob::class)
            ->create(['date' => $monday->addDays(2), 'start_time' => '04:30 pm'])
            ->toArray();
        $earlyWednesdaySocial = factory(SocialMob::class)
            ->create(['date' => $monday->addDays(2), 'start_time' => '03:30 pm'])
            ->toArray();
        $fridaySocial = factory(SocialMob::class)
            ->create(['date' => $monday->addDays(4), 'start_time' => '03:30 pm'])
            ->toArray();
        factory(SocialMob::class)
            ->create(['date' => $monday->addDays(8), 'start_time' => '03:30 pm']); // Socials on another week

        $expectedResponse = [
            $monday->toDateString() => [$mondaySocial],
            $monday->addDays(1)->toDateString() => [],
            $monday->addDays(2)->toDateString() => [$earlyWednesdaySocial, $lateWednesdaySocial],
            $monday->addDays(3)->toDateString() => [],
            $monday->addDays(4)->toDateString() => [$fridaySocial],
        ];

        $user = factory(User::class)->create();

        $response = $this->actingAs($user)->getJson(route('social_mobs.week'));
        $response->assertSuccessful();
        $response->assertJson($expectedResponse);
    }

    public function testItCanProvideAllSocialMobsOfTheCurrentWeekForAuthenticatedUserEvenOnFridays()
    {
        $this->setTestNow('Next Friday');

        $mondaySocial = factory(SocialMob::class)
            ->create(['date' => Carbon::parse('Last Monday')])
            ->toArray();
        $fridaySocial = factory(SocialMob::class)
            ->create(['date' => today()])
            ->toArray();

        $expectedResponse = [
            Carbon::parse('Last Monday')->toDateString() => [$mondaySocial],
            Carbon::parse('Last Tuesday')->toDateString() => [],
            Carbon::parse('Last Wednesday')->toDateString() => [],
            Carbon::parse('Last Thursday')->toDateString() => [],
            today()->toDateString() => [$fridaySocial],
        ];

        $user = factory(User::class)->create();

        $response = $this->actingAs($user)->getJson(route('social_mobs.week'));
        $response->assertSuccessful();
        $response->assertJson($expectedResponse);
    }

    public function testItCanProvideAllSocialMobsOfASpecifiedWeekForAuthenticatedUserIfADateIsGiven()
    {
        $weekThatHasNoMobs = '2020-05-25';
        $this->setTestNow($weekThatHasNoMobs);
        $weekThatHasTheMobs = '2020-05-04';
        $mondayOfWeekWithMobs = CarbonImmutable::parse($weekThatHasTheMobs);

        $mondaySocial = factory(SocialMob::class)
            ->create(['date' => $mondayOfWeekWithMobs, 'start_time' => '03:30 pm'])
            ->toArray();
        $lateWednesdaySocial = factory(SocialMob::class)
            ->create(['date' => $mondayOfWeekWithMobs->addDays(2), 'start_time' => '04:30 pm'])
            ->toArray();
        $earlyWednesdaySocial = factory(SocialMob::class)
            ->create(['date' => $mondayOfWeekWithMobs->addDays(2), 'start_time' => '03:30 pm'])
            ->toArray();
        $fridaySocial = factory(SocialMob::class)
            ->create(['date' => $mondayOfWeekWithMobs->addDays(4), 'start_time' => '03:30 pm'])
            ->toArray();

        $expectedResponse = [
            $mondayOfWeekWithMobs->toDateString() => [$mondaySocial],
            $mondayOfWeekWithMobs->addDays(1)->toDateString() => [],
            $mondayOfWeekWithMobs->addDays(2)->toDateString() => [$earlyWednesdaySocial, $lateWednesdaySocial],
            $mondayOfWeekWithMobs->addDays(3)->toDateString() => [],
            $mondayOfWeekWithMobs->addDays(4)->toDateString() => [$fridaySocial],
        ];

        $user = factory(User::class)->create();

        $response = $this->actingAs($user)->getJson(route('social_mobs.week', ['date' => $weekThatHasTheMobs]));
        $response->assertSuccessful();
        $response->assertJson($expectedResponse);
    }

    public function testItDoesNotProvideLocationOfAllSocialMobsOfASpecifiedWeekForAnonymousUser()
    {
        $this->setTestNow('2020-01-15');
        $monday = CarbonImmutable::parse('Last Monday');
        factory(SocialMob::class)->create(['date' => $monday, 'start_time' => '03:30 pm']);
        factory(SocialMob::class)->create(['date' => $monday->addDays(2), 'start_time' => '04:30 pm']);
        factory(SocialMob::class)->create(['date' => $monday->addDays(2), 'start_time' => '03:30 pm']);
        factory(SocialMob::class)->create(['date' => $monday->addDays(4), 'start_time' => '03:30 pm']);
        factory(SocialMob::class)->create(['date' => $monday->addDays(8), 'start_time' => '03:30 pm']);

        $response = $this->getJson(route('social_mobs.week'));

        $response->assertSuccessful();
        $response->assertDontSee('At AnyDesk XYZ - abcdefg');
    }

    public function testItDoesNotProvideLocationOfASocialMobForAnonymousUser()
    {
        $this->setTestNow('2020-01-15');
        $monday = CarbonImmutable::parse('Last Monday');
        $mob = factory(SocialMob::class)->create(['date' => $monday, 'start_time' => '03:30 pm']);

        $response = $this->get(route('social_mobs.show', ['social_mob' => $mob]));

        $response->assertSuccessful();
        $response->assertDontSee('At AnyDesk XYZ - abcdefg');
    }

    public function testItCanProvideSocialMobLocationForAuthenticatedUser()
    {
        $this->setTestNow('2020-01-15');
        $monday = CarbonImmutable::parse('Last Monday');
        $socialMob = factory(SocialMob::class)->create(['date' => $monday, 'start_time' => '03:30 pm']);

        $user = factory(User::class)->create();
        $response = $this->actingAs($user)->get(route('social_mobs.show', $socialMob));

        $response->assertSuccessful();
        $response->assertSee('At AnyDesk XYZ - abcdefg');
    }

    public function testItProvidesASummaryOfTheMobsOfTheDay()
    {
        $today = '2020-01-02';
        $tomorrow = '2020-01-03';
        $this->setTestNow($today);
        $user = factory(User::class)->create();

        $todayMobs = factory(SocialMob::class, 2)->create(['date' => $today]);
        factory(SocialMob::class, 2)->create(['date' => $tomorrow]);

        $response = $this->actingAs($user)->getJson(route('social_mobs.day'));

        $response->assertJson($todayMobs->toArray());
    }
}
