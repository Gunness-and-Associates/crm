<?php

namespace Database\Seeders;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Database\Seeder;

/**
 * One module (Leads) modelled end-to-end in metadata: fields, option lists,
 * and list + detail layouts that conform to the frozen layout contract.
 * The fixture the frontend lane builds against. Idempotent.
 */
class MetadataFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $leads = Module::updateOrCreate(
            ['key' => 'leads'],
            ['label' => 'Leads', 'table_name' => 'leads', 'base_type' => 'person', 'enabled' => true],
        );

        // Labels: first character capitalised only (never Title Case).
        $vertical = $this->optionList('lead_vertical', 'Lead vertical', [
            'BusinessImmigration' => 'Business immigration',
            'Refugee' => 'Refugee',
            'StudyPermit' => 'Study permit',
            'LMIA' => 'Lmia',
        ]);
        $stage = $this->optionList('lead_stage', 'Stage', [
            'new' => 'New',
            'contacted' => 'Contacted',
            'follow_up' => 'Follow up',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'lost' => 'Lost',
        ]);

        $this->field($leads, 'full_name', 'text', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($leads, 'vertical', 'enum', ['filterable' => true, 'sortable' => true, 'option_list_id' => $vertical->id]);
        $this->field($leads, 'stage', 'enum', ['filterable' => true, 'sortable' => true, 'option_list_id' => $stage->id]);
        $this->field($leads, 'primary_email', 'email', ['filterable' => true, 'sortable' => true, 'max_length' => 255]);
        $this->field($leads, 'phone_mobile', 'phone', ['filterable' => true, 'max_length' => 50]);

        Layout::updateOrCreate(
            ['module_id' => $leads->id, 'view' => 'list'],
            ['definition' => $this->listLayout(), 'version' => 1, 'is_published' => true],
        );
        Layout::updateOrCreate(
            ['module_id' => $leads->id, 'view' => 'detail'],
            ['definition' => $this->detailLayout(), 'version' => 1, 'is_published' => true],
        );
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
    private function listLayout(): array
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
    private function detailLayout(): array
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
