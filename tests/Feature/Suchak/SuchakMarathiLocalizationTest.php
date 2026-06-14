<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakPlan;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Support\Suchak\SuchakLocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakMarathiLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_user_visible_tables_have_marathi_display_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('suchak_accounts', 'suchak_name_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_accounts', 'office_name_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_plans', 'name_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_service_packages', 'package_name_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_customer_agreements', 'agreement_title_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_payment_requests', 'request_title_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_training_modules', 'module_title_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_message_templates', 'body_text_mr'));
        $this->assertTrue(Schema::hasColumn('suchak_contact_numbers', 'label_mr'));
    }

    public function test_localized_text_helper_prefers_marathi_column_only_for_marathi_locale(): void
    {
        $plan = SuchakPlan::factory()->create([
            'name' => 'Suchak Operator',
            'name_mr' => 'सूचक operator',
        ]);

        app()->setLocale('en');
        $this->assertSame('Suchak Operator', SuchakLocalizedText::column($plan, 'name'));

        app()->setLocale('mr');
        $this->assertSame('सूचक operator', SuchakLocalizedText::column($plan, 'name'));
        $this->assertSame('सक्रिय', SuchakLocalizedText::label('active'));
    }

    public function test_registration_page_uses_selected_marathi_locale_with_english_digits(): void
    {
        $this->get(route('suchak.register.info', ['locale' => 'mr']))
            ->assertOk()
            ->assertSee('सूचक नोंदणी', false)
            ->assertSee('वैयक्तिक सूचक', false)
            ->assertSee('10 digit WhatsApp number', false)
            ->assertDontSee('Suchak Registration', false);

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_suchak_dashboard_uses_marathi_plan_columns_without_profile_write(): void
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        $plan = SuchakPlan::factory()->create([
            'name' => 'Suchak Operator',
            'name_mr' => 'सूचक operator',
            'description' => 'English plan description',
            'description_mr' => 'मराठी plan description',
            'is_active' => true,
            'is_visible' => true,
        ]);
        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', ['locale' => 'mr', 'dashboard_tab' => 'money']))
            ->assertOk()
            ->assertSee('सूचक operator', false)
            ->assertSee('मराठी plan description', false);

        $this->assertDatabaseMissing('matrimony_profiles', [
            'user_id' => $user->id,
        ]);
    }
}
