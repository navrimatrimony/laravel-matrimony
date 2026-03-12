<?php

namespace App\View\Components\Forms;

use App\Services\ControlledOptions\ControlledOptionFormEngine;
use Illuminate\View\Component;

class ControlledSelect extends Component
{
    public string $field;

    public string $name;

    /** @var int|string|null|array<int, int|string|null> */
    public $selected;

    public ?string $placeholder;

    public bool $required;

    public ?string $id;

    public ?string $label;

    public bool $disabled;

    public array $meta;

    /**
     * @param  int|string|null|array<int, int|string|null>  $selected
     */
    public function __construct(
        string $field,
        string $name,
        int|string|null|array $selected = null,
        ?string $placeholder = null,
        bool $required = false,
        ?string $id = null,
        ?string $label = null,
        bool $disabled = false,
    ) {
        $this->field = $field;
        $this->name = $name;
        $this->selected = $selected;
        $this->placeholder = $placeholder;
        $this->required = $required;
        $this->id = $id;
        $this->label = $label;
        $this->disabled = $disabled;

        /** @var ControlledOptionFormEngine $formEngine */
        $formEngine = app(ControlledOptionFormEngine::class);
        $this->meta = $formEngine->build($field, $selected, app()->getLocale());
    }

    public function render()
    {
        return view('components.forms.controlled-select');
    }
}

