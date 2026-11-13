<?php

namespace App\Http\Requests\Rooms;

use App\Models\Property;
use App\Models\RoomBlock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The ticked blocks of a bulk End now or Cancel on the Room blocks page.
 */
class BulkRoomBlocksRequest extends FormRequest
{
    /**
     * The `can:rooms.block` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => ['integer', 'distinct', Rule::exists('room_blocks', 'id')->where('property_id', $this->property()->id)],
        ];
    }

    /**
     * The ticked blocks of this property, oldest first, with their room and bed.
     *
     * @return Collection<int, RoomBlock>
     */
    public function blocks(): Collection
    {
        return $this->property()->roomBlocks()
            ->with(['room', 'bed', 'property'])
            ->whereIn('id', array_map(intval(...), (array) $this->validated('block_ids')))
            ->orderBy('starts_on')->orderBy('id')
            ->get();
    }

    protected function property(): Property
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }
}
