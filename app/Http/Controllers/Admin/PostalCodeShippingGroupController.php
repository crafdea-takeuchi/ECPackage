<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostalCodeShippingGroupRequest;
use App\Models\PostalCodeShippingGroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostalCodeShippingGroupController extends Controller
{
    public function store(PostalCodeShippingGroupRequest $request): RedirectResponse
    {
        $this->save($request->validated());

        return to_route('admin.settings.shipping.edit')->with('status', '郵便番号別送料グループを追加しました。');
    }

    public function update(PostalCodeShippingGroupRequest $request, PostalCodeShippingGroup $postalCodeGroup): RedirectResponse
    {
        $this->save($request->validated(), $postalCodeGroup);

        return to_route('admin.settings.shipping.edit')->with('status', '郵便番号別送料グループを更新しました。');
    }

    public function destroy(PostalCodeShippingGroup $postalCodeGroup): RedirectResponse
    {
        DB::transaction(function () use ($postalCodeGroup): void {
            PostalCodeShippingGroup::whereKey($postalCodeGroup->id)->lockForUpdate()->firstOrFail()->delete();
        }, 3);

        return to_route('admin.settings.shipping.edit')->with('status', '郵便番号別送料グループを削除しました。');
    }

    /**
     * @param  array{name: string, shipping_fee: int|string, free_shipping_threshold: int|string|null, postal_codes: list<string>}  $data
     */
    private function save(array $data, ?PostalCodeShippingGroup $group = null): void
    {
        try {
            DB::transaction(function () use ($data, $group): void {
                $locked = $group === null
                    ? new PostalCodeShippingGroup
                    : PostalCodeShippingGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();

                $locked->fill([
                    'name' => $data['name'],
                    'shipping_fee' => bcadd((string) $data['shipping_fee'], '0', 4),
                    'free_shipping_threshold' => $data['free_shipping_threshold'] === null
                        ? null
                        : bcadd((string) $data['free_shipping_threshold'], '0', 4),
                ]);
                $locked->save();

                $locked->postalCodes()->delete();
                $locked->postalCodes()->createMany(array_map(
                    fn (string $postalCode): array => ['postal_code' => $postalCode],
                    $data['postal_codes'],
                ));
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'postal_codes' => 'グループ名または郵便番号が、すでにほかの郵便番号別送料グループへ登録されています。',
                ]);
            }

            throw $exception;
        }
    }
}
