<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ReceiptExpiration;
use App\Enums\CsvImportStatus;
use App\Exceptions\CsvImportFormatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CsvImportUploadRequest;
use App\Jobs\ExecuteCsvImport;
use App\Jobs\ValidateCsvImport;
use App\Models\CsvImport;
use App\Services\Csv\CsvImportManager;
use App\Services\Csv\CsvImportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvImportController extends Controller
{
    public function index(Request $request): Response
    {
        $context = $this->context($request);
        $imports = CsvImport::query()
            ->where('type', $context['type'])
            ->with('creator:id,name')
            ->latest('id')
            ->paginate(20)
            ->through(fn (CsvImport $import): array => $this->serializeImport($import));

        return Inertia::render($context['page_prefix'].'/Index', [
            'imports' => $imports,
            'context' => $context,
        ]);
    }

    public function create(Request $request, CsvImportRegistry $registry): Response
    {
        $context = $this->context($request, $registry);

        return Inertia::render($context['page_prefix'].'/Create', [
            'context' => $context,
            'limits' => [
                'file_size_mb' => ((int) config('csv-imports.max_file_size_kb', 5120)) / 1024,
                'rows' => (int) config('csv-imports.max_rows', 10000),
            ],
        ]);
    }

    public function store(CsvImportUploadRequest $request): RedirectResponse
    {
        $context = $this->context($request);
        $file = $request->file('file');
        $disk = (string) config('csv-imports.disk', 'csv_imports');
        $path = now()->format('Y/m').'/'.Str::uuid().'.csv';
        $contents = $file->getContent();

        Storage::disk($disk)->put($path, $contents);

        $import = CsvImport::create([
            'type' => $context['type'],
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'file_hash' => hash('sha256', $contents),
            'status' => CsvImportStatus::Uploaded,
            'created_by' => $request->user()->id,
        ]);

        ValidateCsvImport::dispatch($import->id)->afterCommit();

        return redirect($context['base_url'].'/'.$import->id)
            ->with('status', 'CSVを受け付けました。検証結果はこの画面に表示されます。');
    }

    public function show(
        Request $request,
        CsvImport $csvImport,
        CsvImportManager $manager,
        CsvImportRegistry $registry,
    ): Response {
        $context = $this->context($request, $registry);
        $this->ensureType($csvImport, $context['type']);
        $csvImport->load('creator:id,name');
        $preview = null;

        if (! $csvImport->status->isProcessing()) {
            try {
                $preview = $manager->preview($csvImport);
            } catch (CsvImportFormatException) {
                $preview = null;
            }
        }

        if ($context['type'] === 'purchase_receipts' && $preview !== null) {
            $preview['rows'] = array_map(function (array $row): array {
                if (isset($row['data']['期限種別'])) {
                    $row['data']['期限種別'] = ReceiptExpiration::displayType($row['data']['期限種別']);
                }

                return $row;
            }, $preview['rows']);
        }

        return Inertia::render($context['page_prefix'].'/Show', [
            'context' => $context,
            'import' => $this->serializeImport($csvImport),
            'preview' => $preview,
            'errors' => $csvImport->errors()->limit(100)->get()->map(fn ($error): array => [
                'id' => $error->id,
                'row_number' => $error->row_number,
                'column' => $error->column,
                'message' => $error->message,
                'raw_data' => $error->raw_data,
            ]),
            'definition_available' => $registry->has($csvImport->type),
            'can_execute' => $csvImport->status === CsvImportStatus::Ready && $registry->has($csvImport->type),
        ]);
    }

    public function execute(
        Request $request,
        CsvImport $csvImport,
        CsvImportRegistry $registry,
    ): RedirectResponse {
        $context = $this->context($request, $registry);
        $this->ensureType($csvImport, $context['type']);

        if ($csvImport->status !== CsvImportStatus::Ready) {
            return back()->withErrors(['csv' => '検証が完了し、エラーがないCSVだけ実行できます。']);
        }

        if (! $registry->has($csvImport->type)) {
            return back()->withErrors(['csv' => 'この管理機能の実登録処理はまだ設定されていません。']);
        }

        ExecuteCsvImport::dispatch($csvImport->id)->afterCommit();

        return back()->with('status', 'CSV取込を開始しました。');
    }

    public function errors(Request $request, CsvImport $csvImport): StreamedResponse
    {
        $context = $this->context($request);
        $this->ensureType($csvImport, $context['type']);
        abort_if($csvImport->error_rows === 0, 404);

        return response()->streamDownload(function () use ($csvImport): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['行番号', '列名', 'エラー内容', '元データ'], ',', '"', '');

            $csvImport->errors()->chunkById(500, function ($errors) use ($stream): void {
                foreach ($errors as $error) {
                    fputcsv($stream, [
                        $error->row_number,
                        $error->column,
                        $error->message,
                        $error->raw_data === null ? '' : json_encode($error->raw_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ], ',', '"', '');
                }
            });

            fclose($stream);
        }, 'csv-import-errors-'.$csvImport->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function template(Request $request, CsvImportRegistry $registry): StreamedResponse
    {
        $context = $this->context($request, $registry);
        $definition = $registry->find($context['type']);
        abort_if($definition === null, 404);

        return response()->streamDownload(function () use ($definition): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $definition->requiredHeaders(), ',', '"', '');

            foreach ($definition->templateRows() as $row) {
                fputcsv($stream, array_map(fn (string $header) => $row[$header] ?? '', $definition->requiredHeaders()), ',', '"', '');
            }

            fclose($stream);
        }, $definition->type().'-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array<string, mixed> */
    private function context(Request $request, ?CsvImportRegistry $registry = null): array
    {
        $type = (string) $request->route('csv_import_type');
        $context = match ($type) {
            'products' => [
                'type' => $type,
                'title' => '販売商品CSV取込',
                'description' => '販売商品をCSVから検証し、一括登録します。',
                'parent_label' => '販売商品管理',
                'parent_url' => '/admin/products',
                'base_url' => '/admin/products/csv-imports',
                'page_prefix' => 'Admin/Products/CsvImports',
                'help' => [
                    '新規の単品商品だけを登録します。既存商品の更新、セット商品、画像は対象外です。',
                    '登録状態は「仮登録／本登録」、公開状態は「非公開／公開」を入力します。',
                    '在庫不足時は「販売停止／取り寄せ」を入力します。',
                    '登録済みの商品分類を完全な階層名で指定します。階層は半角の「>」で表し、複数指定する場合は半角の「|」で区切ります。「>」前後のスペースは省略できます。（例: オーラルケア > 歯ブラシ|各種洗浄/消臭剤 > ハンドソープ）',
                    '属性は 属性コード=値 を | で区切ります（例: color=赤|size=26）。',
                ],
            ],
            'purchase_receipts' => [
                'type' => $type,
                'title' => '入庫CSV取込',
                'description' => '入庫データをCSVから検証し、下書き入庫として一括登録します。',
                'parent_label' => '入庫管理',
                'parent_url' => '/admin/purchase-receipts',
                'base_url' => '/admin/purchase-receipts/csv-imports',
                'page_prefix' => 'Admin/PurchaseReceipts/CsvImports',
                'help' => [
                    '同じ入庫番号の行は、1件の入庫伝票の複数明細としてまとめます。',
                    'CSV取込では下書き入庫を作成します。在庫へ反映するには、取込後に入庫画面で内容を確認して確定してください。',
                    '仕入先コードと商品コードは、登録済みの有効な仕入商品設定と一致する必要があります。',
                    '期限種別と期限日はセットで入力してください。両方が空欄の場合は「期限なし」となります。同じ入庫番号内でも明細ごとに異なる期限を指定できます。',
                ],
            ],
            'stock_operations' => [
                'type' => $type,
                'title' => '出庫・在庫調整CSV取込',
                'description' => '出庫・在庫調整をCSVから検証し、在庫へ一括反映します。',
                'parent_label' => '出庫・在庫調整',
                'parent_url' => '/admin/stock-operations',
                'base_url' => '/admin/stock-operations/csv-imports',
                'page_prefix' => 'Admin/StockOperations/CsvImports',
                'help' => [
                    '1行ごとに1件の在庫操作を登録し、CSV全体を一括で在庫へ反映します。',
                    '数量は常に正の数で入力し、増減方向は「通常出庫／在庫増加／在庫減少」で指定します。',
                    '同じ商品・ロット番号が複数ある場合は、仕入先コードを入力して対象を絞り込んでください。',
                    '既存ロットの数量だけを更新します。新しいロットは入庫CSVから登録してください。',
                ],
            ],
            'shipments' => [
                'type' => $type,
                'title' => '発送CSV取込',
                'description' => '発送をCSVから検証し、一括で新規登録・更新・取消します。',
                'parent_label' => '発送管理',
                'parent_url' => '/admin/shipments',
                'base_url' => '/admin/shipments/csv-imports',
                'page_prefix' => 'Admin/Shipments/CsvImports',
                'help' => [
                    '同じ発送番号の行は1件の発送にまとめ、複数の商品明細を登録できます。',
                    '新しい発送番号は新規登録、登録済みの下書き発送番号は明細を入れ替えて更新します。',
                    '「発送済み」で取り込むと、発送数量に応じて注文ステータスが「発送準備中」または「発送済み」へ自動更新されます。',
                    '発送済み・取消済みの発送は更新できません。取消は登録済みの下書き発送を1行で指定します。',
                    '取り寄せ商品が揃っていない注文は「発送済み」にできません。',
                ],
            ],
            default => abort(404),
        };

        $context['type_label'] = config("csv-imports.types.{$type}", $type);
        $context['fields'] = config("csv-imports.field_descriptions.{$type}", []);
        $context['template_available'] = $registry?->has($type) ?? false;

        return $context;
    }

    private function ensureType(CsvImport $import, string $type): void
    {
        abort_unless($import->type === $type, 404);
    }

    /** @return array<string, mixed> */
    private function serializeImport(CsvImport $import): array
    {
        return [
            'id' => $import->id,
            'type' => $import->type,
            'type_label' => config("csv-imports.types.{$import->type}", $import->type),
            'original_filename' => $import->original_filename,
            'encoding' => $import->encoding,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'is_processing' => $import->status->isProcessing(),
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->valid_rows,
            'error_rows' => $import->error_rows,
            'imported_rows' => $import->imported_rows,
            'system_error' => $import->system_error,
            'created_at' => $import->created_at?->format('Y-m-d H:i:s'),
            'started_at' => $import->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $import->finished_at?->format('Y-m-d H:i:s'),
            'creator' => $import->relationLoaded('creator') ? $import->creator?->only(['id', 'name']) : null,
        ];
    }
}
