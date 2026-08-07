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
 * Registers the three Z-2.4 entities (Company, Lead, Assessment) in the
 * metadata registry, matching their real migrated columns — this is what
 * lets the frontend's DynamicResource render them, and what Studio extends
 * later. Leads carries the full frozen layout contract fixture from Z-1.5;
 * Company and Assessment register fields only for now (their layouts land
 * with the frontend lane's screens). Idempotent.
 */
class MetadataFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $vertical = $this->optionList(
            'lead_vertical',
            'Lead vertical',
            collect(LeadVertical::cases())->mapWithKeys(fn (LeadVertical $v) => [$v->value => $v->label()])->all(),
        );
        $stage = $this->optionList(
            'lead_stage',
            'Stage',
            collect(LeadStage::cases())->mapWithKeys(fn (LeadStage $s) => [$s->value => $s->label()])->all(),
        );

        $this->seedLeads($vertical, $stage);
        $this->seedCompanies();
        $this->seedAssessments();
    }

    private function seedLeads(OptionList $vertical, OptionList $stage): void
    {
        $leads = Module::updateOrCreate(
            ['key' => 'leads'],
            ['label' => 'Leads', 'table_name' => 'leads', 'base_type' => 'person', 'enabled' => true],
        );

        $this->field($leads, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
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

    /**
     * @param  array<string, string>  $items  value => label
     */
    private function optionList(string $key, string $label, array $items): OptionList
    {
        $list = OptionList::updateOrCreate(['key' => $key], ['label' => $label]);

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
