<?php

use App\CsvImports\ProductCsvImportDefinition;
use App\CsvImports\PurchaseReceiptCsvImportDefinition;
use App\CsvImports\ShipmentCsvImportDefinition;
use App\CsvImports\StockOperationCsvImportDefinition;

return [
    'disk' => env('CSV_IMPORT_DISK', 'csv_imports'),
    'max_file_size_kb' => 5120,
    'max_rows' => 10000,
    'preview_rows' => 20,

    'types' => [
        'products' => '販売商品',
        'purchase_receipts' => '入庫',
        'stock_operations' => '出庫・在庫調整',
        'shipments' => '発送',
    ],

    'field_descriptions' => [
        'products' => [
            ['name' => '商品コード', 'requirement' => '必須', 'description' => '販売商品を一意に識別するコードです。既存商品とCSV内の重複は登録できません。半角英数字・ハイフン・アンダースコアを使用できます。', 'example' => 'PAN-001'],
            ['name' => '商品名', 'requirement' => '必須', 'description' => '管理画面とショップに表示する販売商品名です。200文字以内で入力します。', 'example' => '鉄フライパン 26cm'],
            ['name' => '商品説明', 'requirement' => '任意', 'description' => '商品の特徴や注意事項です。5000文字以内で入力します。', 'example' => 'IH・ガス火対応'],
            ['name' => '単位コード', 'requirement' => '必須', 'description' => '単位管理に登録されている有効なコードを入力します。単位名ではなくコードを指定してください。', 'example' => 'piece'],
            ['name' => '登録状態', 'requirement' => '必須', 'description' => '「仮登録」または「本登録」を入力します。', 'example' => '本登録'],
            ['name' => '公開状態', 'requirement' => '必須', 'description' => '「非公開」または「公開」を入力します。仮登録の商品は公開にできません。', 'example' => '公開'],
            ['name' => '在庫不足時', 'requirement' => '必須', 'description' => '在庫が不足した場合の扱いとして「販売停止」または「取り寄せ」を入力します。', 'example' => '取り寄せ'],
            ['name' => '標準販売単価', 'requirement' => '本登録時必須', 'description' => '0以上、整数15桁・小数4桁以内の金額を入力します。仮登録では空欄にできます。', 'example' => '3000'],
            ['name' => '税率コード', 'requirement' => '任意', 'description' => '税率管理に登録されている有効なコードを入力します。', 'example' => 'standard'],
            ['name' => '基本商品', 'requirement' => '任意', 'description' => '有効な基本商品の名前を入力します。属性を入力する場合は指定が必要です。', 'example' => 'フライパン'],
            ['name' => '商品分類', 'requirement' => '任意', 'description' => '登録済みの商品分類を完全な階層名で指定します。階層は半角の「>」で表し、複数指定する場合は半角の「|」で区切ります。「>」前後のスペースは省略できます。', 'example' => 'オーラルケア > 歯ブラシ|各種洗浄/消臭剤 > ハンドソープ'],
            ['name' => '属性', 'requirement' => '条件付き', 'description' => '「属性コード=値」の形式で入力します。複数指定する場合は半角の | で区切ります。本登録では基本商品の必須属性が必要です。', 'example' => 'color=赤|size=26'],
            ['name' => '公開開始日時', 'requirement' => '任意', 'description' => '日本時間（Asia/Tokyo）のYYYY-MM-DD HH:mm:ss形式。空欄の側は制限なし。開始日時から有効、終了日時から無効です。終了は開始より後、販売期間は公開期間内に設定します。', 'example' => '2026-12-01 09:00:00'],
            ['name' => '公開終了日時', 'requirement' => '任意', 'description' => '日本時間（Asia/Tokyo）のYYYY-MM-DD HH:mm:ss形式。空欄の側は制限なし。開始日時から有効、終了日時から無効です。終了は開始より後、販売期間は公開期間内に設定します。', 'example' => '2027-01-01 00:00:00'],
            ['name' => '販売開始日時', 'requirement' => '任意', 'description' => '日本時間（Asia/Tokyo）のYYYY-MM-DD HH:mm:ss形式。空欄の側は制限なし。開始日時から有効、終了日時から無効です。終了は開始より後、販売期間は公開期間内に設定します。', 'example' => '2026-12-01 10:00:00'],
            ['name' => '販売終了日時', 'requirement' => '任意', 'description' => '日本時間（Asia/Tokyo）のYYYY-MM-DD HH:mm:ss形式。空欄の側は制限なし。開始日時から有効、終了日時から無効です。終了は開始より後、販売期間は公開期間内に設定します。', 'example' => '2026-12-31 23:00:00'],
        ],
        'purchase_receipts' => [
            ['name' => '入庫番号', 'requirement' => '必須', 'description' => '入庫伝票を識別する番号です。同じ番号の行は1件の入庫伝票にまとめます。登録済みの番号は使用できません。', 'example' => 'RCV-20261109-001'],
            ['name' => '入庫日', 'requirement' => '必須', 'description' => 'YYYY-MM-DD形式で入力します。同じ入庫番号では統一してください。', 'example' => '2026-11-09'],
            ['name' => '仕入先コード', 'requirement' => '必須', 'description' => '仕入先管理に登録されている有効なコードを入力します。同じ入庫番号では統一してください。', 'example' => 'SUP-001'],
            ['name' => '商品コード', 'requirement' => '必須', 'description' => '販売商品管理の商品コードを入力します。指定した仕入先に有効な仕入商品設定が必要です。', 'example' => 'PAN-001'],
            ['name' => 'ロット番号', 'requirement' => '必須', 'description' => '商品ロットを識別する番号です。同じ入庫番号内で商品コードとの組み合わせを重複できません。', 'example' => 'LOT-202611-A'],
            ['name' => '入庫数量', 'requirement' => '必須', 'description' => '0より大きい数値を入力します。小数桁数は商品の単位設定に従います。', 'example' => '10'],
            ['name' => '仕入単価', 'requirement' => '必須', 'description' => '0以上、整数15桁・小数4桁以内の単価を入力します。', 'example' => '180.5'],
            ['name' => '製造日', 'requirement' => '任意', 'description' => 'YYYY-MM-DD形式で入力します。期限日を入力する場合は期限日以前の日付にしてください。', 'example' => '2026-11-01'],
            ['name' => '期限種別', 'requirement' => '条件付き', 'description' => '期限がある場合は「賞味期限」または「消費期限」を入力します。best_before、use_byも使用できます。期限日とセットで入力してください。両方が空欄の場合は「期限なし」となります。', 'example' => '賞味期限'],
            ['name' => '期限日', 'requirement' => '条件付き', 'description' => '期限種別を入力した場合に、YYYY-MM-DD形式で入力します。製造日がある場合は、製造日以降の日付を入力してください。', 'example' => '2027-05-31'],
            ['name' => '備考', 'requirement' => '任意', 'description' => '入庫伝票の備考です。同じ入庫番号では同じ内容に統一し、2000文字以内で入力します。', 'example' => '午前便'],
        ],
        'stock_operations' => [
            ['name' => '操作種別', 'requirement' => '必須', 'description' => '「通常出庫」「在庫増加」「在庫減少」のいずれかを入力します。outbound、adjustment_increase、adjustment_decreaseも使用できます。', 'example' => '通常出庫'],
            ['name' => '処理日', 'requirement' => '必須', 'description' => '在庫へ反映する日付をYYYY-MM-DD形式で入力します。', 'example' => '2026-11-09'],
            ['name' => '商品コード', 'requirement' => '必須', 'description' => '販売商品管理の商品コードを入力します。', 'example' => 'PAN-001'],
            ['name' => 'ロット番号', 'requirement' => '必須', 'description' => '登録済みの在庫ロット番号を入力します。商品コードとの組み合わせで照合します。在庫増加でも新しいロットは作成しません。', 'example' => 'LOT-202611-A'],
            ['name' => '仕入先コード', 'requirement' => '任意', 'description' => '同じ商品・ロット番号が複数ある場合に、仕入先コードで対象ロットを絞り込みます。', 'example' => 'SUP-001'],
            ['name' => '数量', 'requirement' => '必須', 'description' => '常に0より大きい数値を入力します。在庫を減らす場合もマイナス記号は付けません。', 'example' => '3'],
            ['name' => '理由', 'requirement' => '任意', 'description' => '出庫・増減の理由を500文字以内で入力します。', 'example' => '破損品の廃棄'],
            ['name' => '備考', 'requirement' => '任意', 'description' => '在庫操作全体の補足を2000文字以内で入力します。CSVでは1行ごとに1件の在庫操作を登録します。', 'example' => '棚卸結果を反映'],
        ],
        'shipments' => [
            ['name' => '発送番号', 'requirement' => '必須', 'description' => '発送を識別する番号です。同じ番号の行は1件の発送にまとめます。新しい番号は新規登録、登録済みの下書き番号は更新になります。', 'example' => 'SHP-20261109-001'],
            ['name' => '注文番号', 'requirement' => '必須', 'description' => '注文管理に登録されている注文番号を入力します。同じ発送番号では統一してください。', 'example' => 'ORD-20261109-001'],
            ['name' => '発送ステータス', 'requirement' => '必須', 'description' => '「発送準備中」「発送済み」「取消」のいずれかを入力します。「下書き」またはdraftも使用できます。発送済みにすると注文状態も自動更新されます。', 'example' => '発送準備中'],
            ['name' => '商品コード', 'requirement' => '条件付き', 'description' => '下書きまたは発送済みの場合に、注文明細の商品コードを入力します。同じ発送番号内で重複できません。', 'example' => 'PAN-001'],
            ['name' => '発送数量', 'requirement' => '条件付き', 'description' => '下書きまたは発送済みの場合に、0より大きい数値を入力します。未発送数量を超えて登録できません。', 'example' => '2'],
            ['name' => '配送会社', 'requirement' => '発送済み時必須', 'description' => '発送済みにする場合は配送会社名を入力します。100文字以内です。', 'example' => 'ヤマト運輸'],
            ['name' => '追跡番号', 'requirement' => '任意', 'description' => '配送会社の送り状番号・追跡番号を100文字以内で入力します。', 'example' => '1234-5678-9012'],
            ['name' => '発送予定日', 'requirement' => '任意', 'description' => 'YYYY-MM-DD形式で入力します。', 'example' => '2026-11-10'],
            ['name' => '発送日', 'requirement' => '発送済み時必須', 'description' => '発送済みにする場合にYYYY-MM-DD形式で入力します。', 'example' => '2026-11-09'],
            ['name' => '取消理由', 'requirement' => '取消時必須', 'description' => '登録済みの下書き発送を取り消す場合に入力します。取消行では商品コードと発送数量を空欄にします。', 'example' => '配送先変更のため作り直し'],
            ['name' => '備考', 'requirement' => '任意', 'description' => '発送に関する補足を2000文字以内で入力します。', 'example' => '午前中指定'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Business import definitions
    |--------------------------------------------------------------------------
    |
    | Register CsvImportDefinition implementations here as each business
    | import is added. The common reader and validation UI work without them.
    |
    */
    'definitions' => [
        'products' => ProductCsvImportDefinition::class,
        'purchase_receipts' => PurchaseReceiptCsvImportDefinition::class,
        'stock_operations' => StockOperationCsvImportDefinition::class,
        'shipments' => ShipmentCsvImportDefinition::class,
    ],
];
