<?php

namespace Database\Seeders;

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Database\Seeder;

/**
 * Registers the Z-2.4/Z-2.5 entities (Company, Lead, Assessment, Student,
 * Client, Affiliate, NewsletterSubscriber) in the metadata registry, matching
 * their real migrated columns — this is what lets the frontend's
 * DynamicResource render them, and what Studio extends later. Leads carries
 * the full frozen layout contract fixture from Z-1.5; the rest register
 * fields only for now (their layouts land with the frontend lane's screens).
 * Idempotent.
 */
class MetadataFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $vertical = $this->optionList(
            'lead_vertical',
            'Lead vertical',
            collect(LeadVertical::cases())->mapWithKeys(fn (LeadVertical $v) => [$v->value => $v->label()])->all(),
            isSystem: true,
        );
        $stage = $this->optionList(
            'lead_stage',
            'Stage',
            collect(LeadStage::cases())->mapWithKeys(fn (LeadStage $s) => [$s->value => $s->label()])->all(),
            isSystem: true,
        );

        $this->seedLeads($vertical, $stage);
        $this->seedCompanies();
        $this->seedAssessments();
        $this->seedStudents();
        $this->seedClients();
        $this->seedAffiliates();
        $this->seedNewsletterSubscribers();
    }

    private function seedLeads(OptionList $vertical, OptionList $stage): void
    {
        $leads = Module::updateOrCreate(
            ['key' => 'leads'],
            ['label' => 'Leads', 'table_name' => 'leads', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($leads, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        // Z-5.6: full_name has no real column or mutator (Contactable::fullName()
        // is a plain read-only method, not an Eloquent accessor/mutator) — the
        // ingest pipeline (and any other writer) needs the real columns registered.
        $this->field($leads, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($leads, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($leads, 'vertical', 'enum', ['filterable' => true, 'sortable' => true, 'option_list_id' => $vertical->id]);
        $this->field($leads, 'stage', 'enum', ['filterable' => true, 'sortable' => true, 'option_list_id' => $stage->id]);
        $this->field($leads, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($leads, 'phone_mobile', 'phone', ['filterable' => true, 'max_length' => 50]);
        $this->field($leads, 'source', 'text', ['filterable' => true, 'max_length' => 255]);
        $this->field($leads, 'hot_lead', 'bool', ['filterable' => true]);
        $this->field($leads, 'warm_lead', 'bool', ['filterable' => true]);
        $this->field($leads, 'do_not_call', 'bool', ['filterable' => true]);
        $this->field($leads, 'last_contacted_at', 'datetime', ['filterable' => true, 'sortable' => true]);
        $this->field($leads, 'next_follow_up_at', 'datetime', ['filterable' => true, 'sortable' => true]);

        Layout::updateOrCreate(
            ['module_id' => $leads->id, 'view' => 'list'],
            ['definition' => $this->leadsListLayout(), 'version' => 1, 'is_published' => true],
        );
        Layout::updateOrCreate(
            ['module_id' => $leads->id, 'view' => 'detail'],
            ['definition' => $this->leadsDetailLayout(), 'version' => 1, 'is_published' => true],
        );
    }

    private function seedCompanies(): void
    {
        $companies = Module::updateOrCreate(
            ['key' => 'companies'],
            ['label' => 'Companies', 'table_name' => 'companies', 'base_type' => 'company', 'enabled' => true],
        );

        $this->field($companies, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        // Z-5.6/full_name fix: the real Contactable columns full_name has no
        // accessor/mutator for — see app/Support/FullName.php.
        $this->field($companies, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($companies, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($companies, 'contact_person_name', 'text', ['filterable' => true, 'max_length' => 255]);
        $this->field($companies, 'contact_person_phone', 'phone', ['max_length' => 50]);
        $this->field($companies, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($companies, 'industry', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($companies, 'company_contact_status', 'text', ['filterable' => true, 'max_length' => 60]);
        $this->field($companies, 'lmia', 'text', ['filterable' => true, 'max_length' => 20]);
        $this->field($companies, 'website', 'url', ['max_length' => 500]);
    }

    private function seedAssessments(): void
    {
        $assessments = Module::updateOrCreate(
            ['key' => 'assessments'],
            ['label' => 'Assessments', 'table_name' => 'assessments', 'base_type' => 'generic', 'enabled' => true],
        );

        $this->field($assessments, 'first_name', 'text', ['filterable' => true, 'max_length' => 100]);
        $this->field($assessments, 'last_name', 'text', ['filterable' => true, 'max_length' => 100]);
        $this->field($assessments, 'case_type', 'text', ['filterable' => true, 'max_length' => 20]);
        $this->field($assessments, 'status', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 20]);
        $this->field($assessments, 'crs_score', 'int', ['filterable' => true, 'sortable' => true]);
        $this->field($assessments, 'fsw_score', 'int', ['filterable' => true, 'sortable' => true]);
    }

    private function seedStudents(): void
    {
        $students = Module::updateOrCreate(
            ['key' => 'students'],
            ['label' => 'Students', 'table_name' => 'students', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($students, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($students, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($students, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($students, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($students, 'phone_mobile', 'phone', ['filterable' => true, 'max_length' => 50]);
        $this->field($students, 'status', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 60]);
        $this->field($students, 'get_started', 'text', ['filterable' => true, 'max_length' => 255]);
        $this->field($students, 'hot_lead', 'bool', ['filterable' => true]);
        $this->field($students, 'warm_lead', 'bool', ['filterable' => true]);
    }

    private function seedClients(): void
    {
        $clients = Module::updateOrCreate(
            ['key' => 'clients'],
            ['label' => 'Clients', 'table_name' => 'clients', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($clients, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($clients, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($clients, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($clients, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($clients, 'client_status', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 60]);
        $this->field($clients, 'case_type', 'text', ['filterable' => true, 'max_length' => 60]);
        $this->field($clients, 'fee_status', 'text', ['filterable' => true, 'max_length' => 30]);
        $this->field($clients, 'next_action_at', 'datetime', ['filterable' => true, 'sortable' => true]);
    }

    private function seedAffiliates(): void
    {
        $affiliates = Module::updateOrCreate(
            ['key' => 'affiliates'],
            ['label' => 'Affiliates', 'table_name' => 'affiliates', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($affiliates, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($affiliates, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($affiliates, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($affiliates, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($affiliates, 'username', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($affiliates, 'commission', 'decimal', ['filterable' => true, 'sortable' => true]);
        $this->field($affiliates, 'status', 'text', ['filterable' => true, 'max_length' => 30]);
    }

    private function seedNewsletterSubscribers(): void
    {
        $subscribers = Module::updateOrCreate(
            ['key' => 'newsletter_subscribers'],
            ['label' => 'Newsletter subscribers', 'table_name' => 'newsletter_subscribers', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($subscribers, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($subscribers, 'first_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($subscribers, 'last_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 100]);
        $this->field($subscribers, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($subscribers, 'status', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 30]);
        $this->field($subscribers, 'source', 'text', ['filterable' => true, 'max_length' => 255]);
    }

    /**
     * @param  array<string, string>  $items  value => label
     */
    private function optionList(string $key, string $label, array $items, bool $isSystem = false): OptionList
    {
        $list = OptionList::updateOrCreate(['key' => $key], ['label' => $label, 'is_system' => $isSystem]);

        $order = 0;
        foreach ($items as $value => $itemLabel) {
            OptionItem::updateOrCreate(
                ['option_list_id' => $list->id, 'value' => $value],
                ['label' => $itemLabel, 'sort_order' => $order++],
            );
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function field(Module $module, string $name, string $type, array $attributes = []): Field
    {
        return Field::updateOrCreate(
            ['module_id' => $module->id, 'name' => $name],
            array_merge(['type' => $type, 'label_key' => 'LBL_'.strtoupper($name), 'storage' => 'column'], $attributes),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function leadsListLayout(): array
    {
        return [
            'version' => 1,
            'view' => 'list',
            'module' => 'leads',
            'content' => [
                'default_sort' => ['field' => 'created_at', 'direction' => 'desc'],
                'columns' => [
                    ['field' => 'full_name', 'priority' => 1, 'link' => true, 'width' => 200],
                    ['field' => 'vertical', 'priority' => 1, 'width' => 150],
                    ['field' => 'stage', 'priority' => 1, 'width' => 120],
                    ['field' => 'primary_email', 'priority' => 1],
                    ['field' => 'phone_mobile', 'priority' => 1, 'sortable' => false],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadsDetailLayout(): array
    {
        return [
            'version' => 1,
            'view' => 'detail',
            'module' => 'leads',
            'content' => [
                'panels' => [
                    [
                        'key' => 'contact_details',
                        'label' => 'Contact details',
                        'order' => 0,
                        'columns' => 2,
                        'rows' => [
                            [['field' => 'primary_email'], ['field' => 'phone_mobile']],
                            [['field' => 'full_name', 'span' => 'full']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
