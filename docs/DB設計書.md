# ECPackage DB設計書（テーブル定義書）

* 文書版数：1.0
* 作成日：2026-10-23
* 対象：接続中の開発MySQL（compose.yamlのDBイメージはMySQL 8.4）
* 対象構造：2026\_10\_22\_025536\_create\_shipments\_tableまでの全15マイグレーション適用済み
* 関連文書：[基本設計書](BASIC_DESIGN.md)
* 調査方法：Laravel Schemaによるカラム・索引・外部キーの読み取り。業務レコードは抽出していない。

## 1\. 記載規約・共通方針

本書はマイグレーションの最終形と確認時の実DB構造を基に作成した。対象は33テーブル（認証・業務27、キャッシュ・ジョブ・マイグレーション管理6）。MySQLが外部キー維持のため作成した索引も含む。別環境との差分は別途確認する。

* 型は実DBの物理型。VARCHAR(n)は文字数、DECIMAL(19,4)は全19桁・小数4桁、日時の(6)はマイクロ秒精度。
* NULL可否と画面の必須は別。products.default\_sale\_priceはDBでNULL可だが本登録時は必須。
* DB既定値「なし」は非NULL列で既定値なし。「NULL」はNULL可列でメタデータがNULLを返したもの。「なし／自動採番」はAUTO\_INCREMENT。
* PK＝主キー、UQ＝単一列一意索引、FK＝外部キー。複合制約と列順は各索引表に明記。
* 文字列の照合順序は取得定義ではutf8mb4\_unicode\_ci。厳密な大文字小文字区別が必要なら別途設計。
* 数量・価格・金額は原則DECIMAL(19,4)。数量精度はunits.quantity\_scaleも検証。
* created\_at/updated\_atは多くの表でNULL可。業務表は主にtimestamp(6)、認証系はtimestamp、確定等の日時はdatetime(6)。
* 外部キーの参照先は原則id。RESTRICT/CASCADEを個別に明記。CASCADEは画面での削除許可を意味しない。
* 状態列はVARCHARでENUMではない。許容値・正数条件・状態遷移は主にアプリで検証。
* deleted\_atはない。無効化・取消はstatus等で表現。
* 編集はlock\_version、在庫等の確定はトランザクションと行ロックを使用。
* 接続秘密値やユーザー等の実データは含めない。

## 2\. テーブル一覧

|No.|物理名|論理名|用途|
|-|-|-|-|
|1|[users](#table-users)|ユーザー|顧客・管理者の認証情報と権限。|
|2|[password\_reset\_tokens](#table-password_reset_tokens)|パスワード再設定|メールアドレス単位の再設定トークン。|
|3|[sessions](#table-sessions)|セッション|ログイン状態・カート等のセッション内容。|
|4|[units](#table-units)|単位|販売数量の単位と小数桁数。|
|5|[categories](#table-categories)|商品分類|自己参照による分類階層。|
|6|[category\_product](#table-category_product)|商品分類関連|分類と販売商品の多対多中間テーブル。|
|7|[product\_groups](#table-product_groups)|基本商品|販売商品が属する共通属性グループ。|
|8|[product\_attribute\_definitions](#table-product_attribute_definitions)|商品属性定義|基本商品ごとの属性コード・入力形式・選択肢。|
|9|[product\_attribute\_values](#table-product_attribute_values)|商品属性値|販売商品の属性値。数値形式も文字列として保存。|
|10|[products](#table-products)|販売商品|単品・セットのSKU、単位、価格、登録・公開・欠品方針。|
|11|[product\_bundle\_components](#table-product_bundle_components)|セット構成|セットと単品の対応、1セットあたりの構成数量。|
|12|[suppliers](#table-suppliers)|仕入先|仕入先の連絡先・状態。|
|13|[product\_suppliers](#table-product_suppliers)|商品仕入条件|仕入先と単品ごとの単価・納期・通常仕入先設定。|
|14|[purchase\_receipts](#table-purchase_receipts)|仕入入庫伝票|入庫のヘッダ。下書きと確定を管理。|
|15|[purchase\_receipt\_lines](#table-purchase_receipt_lines)|仕入入庫明細|商品、ロット番号、数量、単価。確定時にロットを生成。|
|16|[inventory\_lots](#table-inventory_lots)|ロット在庫|入庫明細ごとの残量。受注引当で減るため物理実在庫とは異なる。|
|17|[stock\_movements](#table-stock_movements)|在庫移動履歴|入庫・出庫・調整・取消戻入・再引当の増減記録。|
|18|[stock\_operations](#table-stock_operations)|在庫操作|手動出庫・調整、受注出庫、取消戻入、再引当のヘッダ。|
|19|[stock\_operation\_lines](#table-stock_operation_lines)|在庫操作明細|ロットごとの変更前・増減・変更後数量。|
|20|[orders](#table-orders)|注文|顧客、受付元、金額、状態、確定・取消情報。|
|21|[order\_lines](#table-order_lines)|注文明細|販売商品・単価・単位名のスナップショットと数量。|
|22|[order\_line\_components](#table-order_line_components)|注文セット構成|受注確定時のセット構成スナップショット。|
|23|[order\_backorders](#table-order_backorders)|取り寄せ|単品またはセット構成品単位の不足と解消履歴。|
|24|[order\_allocations](#table-order_allocations)|注文引当|注文と在庫ロット・在庫操作明細の対応。|
|25|[order\_settings](#table-order_settings)|注文設定|取消可能状態のJSON配列。アプリはid=1を利用。|
|26|[shipments](#table-shipments)|発送伝票|注文単位の配送情報と発送・取消状態。|
|27|[shipment\_lines](#table-shipment_lines)|発送明細|注文明細ごとの発送数量。分割発送を許容。|
|28|[cache](#table-cache)|キャッシュ|databaseキャッシュストア用。存在と利用ドライバは別。|
|29|[cache\_locks](#table-cache_locks)|キャッシュロック|databaseストアの分散ロック用。|
|30|[jobs](#table-jobs)|キュージョブ|databaseキュードライバ用の待機ジョブ。|
|31|[job\_batches](#table-job_batches)|ジョブバッチ|バッチの進捗・失敗・取消情報。|
|32|[failed\_jobs](#table-failed_jobs)|失敗ジョブ|失敗したジョブの内容と例外。|
|33|[migrations](#table-migrations)|マイグレーション履歴|Laravelの適用履歴。業務モデルではない。|

## 3\. ER図（主要業務関係）

作成者・確定者・取消者などusersへの監査参照は省略。全外部キーは第5章を正とする。任意参照では子から見た親は0または1。DB上は明細0件も可能であり、フォームの最低明細数と区別する。

### 3.1 商品・仕入・在庫

\~\~\~mermaid
erDiagram
units ||--o{ products : unit
product\_groups |o--o{ products : group
product\_groups ||--o{ product\_attribute\_definitions : definitions
products ||--o{ product\_attribute\_values : values
product\_attribute\_definitions ||--o{ product\_attribute\_values : definition
categories |o--o{ categories : parent
categories ||--o{ category\_product : category
products ||--o{ category\_product : product
products ||--o{ product\_bundle\_components : bundle
products ||--o{ product\_bundle\_components : component
suppliers ||--o{ product\_suppliers : supplier
products ||--o{ product\_suppliers : product
suppliers ||--o{ purchase\_receipts : supplier
purchase\_receipts ||--o{ purchase\_receipt\_lines : lines
products ||--o{ purchase\_receipt\_lines : product
product\_suppliers ||--o{ purchase\_receipt\_lines : setting
purchase\_receipt\_lines ||--o| inventory\_lots : lot
products ||--o{ inventory\_lots : product
inventory\_lots |o--o{ stock\_movements : movements
stock\_operations ||--o{ stock\_operation\_lines : lines
inventory\_lots ||--o{ stock\_operation\_lines : lot
\~\~\~

### 3.2 受注・取り寄せ・発送

\~\~\~mermaid
erDiagram
users ||--o{ orders : customer
orders ||--o{ order\_lines : lines
products ||--o{ order\_lines : product
order\_lines ||--o{ order\_line\_components : snapshot
products ||--o{ order\_line\_components : component
order\_lines ||--o{ order\_backorders : shortage
order\_line\_components |o--o{ order\_backorders : component
products ||--o{ order\_backorders : stock\_product
order\_lines ||--o{ order\_allocations : allocations
order\_line\_components |o--o{ order\_allocations : component
order\_backorders |o--o{ order\_allocations : backorder
inventory\_lots ||--o{ order\_allocations : lot
stock\_operation\_lines ||--o| order\_allocations : operation\_line
orders ||--o{ shipments : shipments
shipments ||--o{ shipment\_lines : lines
order\_lines ||--o{ shipment\_lines : order\_line
\~\~\~

## 4\. 値域・JSON・整合性

### 4.1 状態・区分

|列|アプリ上の値|
|-|-|
|users.role|customer：顧客、admin：管理者|
|units/categories/product\_groups/product\_attribute\_definitions/suppliers/product\_suppliers.status|active：有効、inactive：無効|
|products.kind|single：単品、bundle：セット|
|products.registration\_status|provisional：仮登録、confirmed：本登録|
|products.publication\_status|private：非公開、public：公開|
|products.shortage\_policy|reject：不足拒否、backorder：取り寄せ受付|
|product\_attribute\_definitions.input\_type|text、number、select|
|purchase\_receipts.status|draft：下書き、posted：確定|
|inventory\_lots.status|active：残量あり、depleted：残量0|
|stock\_operations.operation\_type|outbound、adjustment、cancellation\_return、backorder\_allocation|
|stock\_movements.movement\_type|上記4値とinbound|
|stock\_movements.source\_type|purchase\_receipt\_line、stock\_operation\_line|
|orders.order\_source|admin、customer|
|orders.status|draft、confirmed、preparing、shipped、completed、cancelled|
|shipments.status|draft、shipped、cancelled|

order\_backordersにstatus列はない。remaining\_quantity、fulfilled\_at、親注文の状態から判定する。

### 4.2 JSON・初期データ

|対象|形式・初期値|
|-|-|
|product\_attribute\_definitions.options|selectの文字列配列。例：\["赤","青"]|
|order\_settings.cancellable\_statuses|初期\["draft","confirmed","preparing"]。空配列可。アプリはid=1を利用|
|products.attributes|NULL可JSON。現行属性編集はproduct\_attribute\_valuesを利用し、固定構造は未定義|
|units|UnitSeeder：piece/個/0、set/セット/0、kg/kg/3、m/m/3。末尾は数量小数桁|
|order\_settings|マイグレーションでid=1と取消可能状態を挿入。Modelにも未存在時の作成処理あり|

初期値はSeeder・Migrationの定義であり、抽出した業務データではない。

### 4.3 DB制約と業務制約の区別

* category\_productは複合主キーのみでid・日時なし。
* 商品＋属性定義、セット＋構成品はそれぞれ複合一意。
* 分類循環、セット自己参照・入れ子、顧客role、単位精度はDB外部キーだけでは保証しない。
* product\_suppliersの仕入先＋商品は一意。「商品ごと通常仕入先1つ」はアプリ制御で、条件付き一意制約なし。
* inventory\_lots.purchase\_receipt\_line\_idは一意で1入庫明細から最大1ロット。lot\_number自体は非一意。
* stock\_movements.source\_type＋source\_idは一意。多態参照のためsource\_idに外部キーなし。
* order\_allocations.stock\_operation\_line\_idは一意。注文明細＋ロットは通常索引で再引当による複数行を許容。
* order\_line\_componentsは確定時スナップショット。後日の構成マスタ変更を過去注文へ反映しない。
* orders.stock\_operation\_idは初回出庫。追加再引当はorder\_allocationsから在庫操作明細を追跡する。
* 取消後も引当履歴を残し、戻入操作を追加。不足残を0にしてもfulfilled\_atを設定するわけではない。
* sessions.user\_idは索引あり・外部キーなし。password\_reset\_tokens.emailにもusersへの外部キーなし。
* order\_settingsはアプリ上単一行だがDBはid=1以外の行を禁止しない。
* 発送明細の所属注文一致・累計上限、入庫の商品・仕入先と仕入条件の一致はアプリで検証。
* 正数、在庫非負、状態値域を包括的に保証するCHECK制約はマイグレーションに定義されていない。

## 5\. テーブル定義

以下は実DBのカラム・索引・外部キー。論理名・説明は実装から付与したもの。索引表には主キー・一意索引・外部キー補助索引をすべて含む。

<a id="table-users"></a>

### users（ユーザー）

顧客・管理者の認証情報と権限。

定義元：[0001\_01\_01\_000000\_create\_users\_table.php](database/migrations/0001_01_01_000000_create_users_table.php)、[2026\_10\_19\_063559\_add\_role\_to\_users\_table.php](database/migrations/2026_10_19_063559_add_role_to_users_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|name|ユーザー氏名（画面入力上限100文字）|varchar(255)|不可|なし|—|
|email|メールアドレス|varchar(255)|不可|なし|UQ|
|email\_verified\_at|メール確認日時|timestamp|可|NULL|—|
|password|ハッシュ化パスワード|varchar(255)|不可|なし|—|
|remember\_token|ログイン保持トークン|varchar(100)|可|NULL|—|
|created\_at|作成日時|timestamp|可|NULL|—|
|updated\_at|更新日時|timestamp|可|NULL|—|
|role|権限（customer/admin）|varchar(255)|不可|customer|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|users\_email\_unique|email|UNIQUE|btree|

**外部キー**

なし。

<a id="table-password\\\_reset\\\_tokens"></a>

### password\_reset\_tokens（パスワード再設定）

メールアドレス単位の再設定トークン。

定義元：[0001\_01\_01\_000000\_create\_users\_table.php](database/migrations/0001_01_01_000000_create_users_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|email|メールアドレス|varchar(255)|不可|なし|PK|
|token|再設定トークン|varchar(255)|不可|なし|—|
|created\_at|作成日時|timestamp|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|email|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-sessions"></a>

### sessions（セッション）

ログイン状態・カート等のセッション内容。

定義元：[0001\_01\_01\_000000\_create\_users\_table.php](database/migrations/0001_01_01_000000_create_users_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|varchar(255)|不可|なし|PK|
|user\_id|ユーザーID|bigint unsigned|可|NULL|—|
|ip\_address|IPアドレス|varchar(45)|可|NULL|—|
|user\_agent|ユーザーエージェント|text|可|NULL|—|
|payload|シリアライズされた内容|longtext|不可|なし|—|
|last\_activity|最終活動時刻（Unix秒）|int|不可|なし|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|sessions\_last\_activity\_index|last\_activity|INDEX|btree|
|sessions\_user\_id\_index|user\_id|INDEX|btree|

**外部キー**

なし。

<a id="table-units"></a>

### units（単位）

販売数量の単位と小数桁数。

定義元：[2026\_10\_20\_015653\_create\_units\_and\_products\_tables.php](database/migrations/2026_10_20_015653_create_units_and_products_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|code|コード|varchar(20)|不可|なし|UQ|
|name|名称|varchar(30)|不可|なし|—|
|quantity\_scale|数量の許容小数桁数|tinyint unsigned|不可|0|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|units\_code\_unique|code|UNIQUE|btree|

**外部キー**

なし。

<a id="table-categories"></a>

### categories（商品分類）

自己参照による分類階層。

定義元：[2026\_10\_20\_063046\_create\_categories\_and\_category\_product\_tables.php](database/migrations/2026_10_20_063046_create_categories_and_category_product_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|name|名称|varchar(100)|不可|なし|—|
|parent\_id|親分類ID|bigint unsigned|可|NULL|FK|
|sort\_order|表示順|int unsigned|不可|0|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|categories\_parent\_id\_sort\_order\_index|parent\_id, sort\_order|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|categories\_parent\_id\_foreign|parent\_id|categories(id)|RESTRICT|NO ACTION|

<a id="table-category\\\_product"></a>

### category\_product（商品分類関連）

分類と販売商品の多対多中間テーブル。

定義元：[2026\_10\_20\_063046\_create\_categories\_and\_category\_product\_tables.php](database/migrations/2026_10_20_063046_create_categories_and_category_product_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|category\_id|分類ID|bigint unsigned|不可|なし|PK, FK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|PK, FK|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|category\_product\_product\_id\_foreign|product\_id|INDEX|btree|
|primary|category\_id, product\_id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|category\_product\_category\_id\_foreign|category\_id|categories(id)|RESTRICT|NO ACTION|
|category\_product\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|

<a id="table-product\\\_groups"></a>

### product\_groups（基本商品）

販売商品が属する共通属性グループ。

定義元：[2026\_10\_20\_064443\_create\_product\_groups\_and\_attributes\_tables.php](database/migrations/2026_10_20_064443_create_product_groups_and_attributes_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|name|名称|varchar(150)|不可|なし|—|
|description|説明|text|可|NULL|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|product\_groups\_status\_name\_index|status, name|INDEX|btree|

**外部キー**

なし。

<a id="table-product\\\_attribute\\\_definitions"></a>

### product\_attribute\_definitions（商品属性定義）

基本商品ごとの属性コード・入力形式・選択肢。

定義元：[2026\_10\_20\_064443\_create\_product\_groups\_and\_attributes\_tables.php](database/migrations/2026_10_20_064443_create_product_groups_and_attributes_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|product\_group\_id|基本商品ID|bigint unsigned|不可|なし|FK|
|name|名称|varchar(80)|不可|なし|—|
|code|コード|varchar(50)|不可|なし|—|
|input\_type|入力形式（text/number/select）|varchar(20)|不可|text|—|
|options|selectの文字列選択肢配列|json|可|NULL|—|
|is\_required|必須入力フラグ|tinyint(1)|不可|0|—|
|sort\_order|表示順|int unsigned|不可|0|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|product\_attribute\_definition\_list|product\_group\_id, status, sort\_order|INDEX|btree|
|product\_attribute\_definitions\_product\_group\_id\_code\_unique|product\_group\_id, code|UNIQUE|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|product\_attribute\_definitions\_product\_group\_id\_foreign|product\_group\_id|product\_groups(id)|RESTRICT|NO ACTION|

<a id="table-product\\\_attribute\\\_values"></a>

### product\_attribute\_values（商品属性値）

販売商品の属性値。数値形式も文字列として保存。

定義元：[2026\_10\_20\_064443\_create\_product\_groups\_and\_attributes\_tables.php](database/migrations/2026_10_20_064443_create_product_groups_and_attributes_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|product\_attribute\_definition\_id|属性定義ID|bigint unsigned|不可|なし|FK|
|value|値|varchar(255)|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|product\_attribute\_value\_search|product\_attribute\_definition\_id, value|INDEX|btree|
|product\_attribute\_value\_unique|product\_id, product\_attribute\_definition\_id|UNIQUE|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|product\_attribute\_values\_product\_attribute\_definition\_id\_foreign|product\_attribute\_definition\_id|product\_attribute\_definitions(id)|RESTRICT|NO ACTION|
|product\_attribute\_values\_product\_id\_foreign|product\_id|products(id)|CASCADE|NO ACTION|

<a id="table-products"></a>

### products（販売商品）

単品・セットのSKU、単位、価格、登録・公開・欠品方針。

定義元：[2026\_10\_20\_015653\_create\_units\_and\_products\_tables.php](database/migrations/2026_10_20_015653_create_units_and_products_tables.php)、[2026\_10\_20\_064443\_create\_product\_groups\_and\_attributes\_tables.php](database/migrations/2026_10_20_064443_create_product_groups_and_attributes_tables.php)、[2026\_10\_21\_040000\_add\_order\_lifecycle\_and\_shortage\_policy.php](database/migrations/2026_10_21_040000_add_order_lifecycle_and_shortage_policy.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|sku|商品コード|varchar(50)|不可|なし|UQ|
|name|名称|varchar(200)|不可|なし|—|
|kind|商品種別（single/bundle）|varchar(20)|不可|single|—|
|product\_group\_id|基本商品ID|bigint unsigned|可|NULL|FK|
|unit\_id|単位ID|bigint unsigned|不可|なし|FK|
|registration\_status|登録状態（provisional/confirmed）|varchar(20)|不可|provisional|—|
|publication\_status|公開状態（private/public）|varchar(20)|不可|private|—|
|shortage\_policy|不足時方針（reject/backorder）|varchar(20)|不可|reject|—|
|default\_sale\_price|標準販売単価|decimal(19,4)|可|NULL|—|
|attributes|拡張属性JSON。現行属性編集はproduct\_attribute\_valuesを使用|json|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|confirmed\_by|確定ユーザーID|bigint unsigned|可|NULL|FK|
|confirmed\_at|確定日時|datetime(6)|可|NULL|—|
|merged\_into\_id|統合先商品ID。統合操作は現行ルートで未確認|bigint unsigned|可|NULL|FK|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|products\_confirmed\_by\_foreign|confirmed\_by|INDEX|btree|
|products\_created\_by\_foreign|created\_by|INDEX|btree|
|products\_merged\_into\_id\_foreign|merged\_into\_id|INDEX|btree|
|products\_product\_group\_id\_index|product\_group\_id|INDEX|btree|
|products\_registration\_status\_publication\_status\_index|registration\_status, publication\_status|INDEX|btree|
|products\_sku\_unique|sku|UNIQUE|btree|
|products\_unit\_id\_foreign|unit\_id|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|products\_confirmed\_by\_foreign|confirmed\_by|users(id)|RESTRICT|NO ACTION|
|products\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|
|products\_merged\_into\_id\_foreign|merged\_into\_id|products(id)|RESTRICT|NO ACTION|
|products\_product\_group\_id\_foreign|product\_group\_id|product\_groups(id)|RESTRICT|NO ACTION|
|products\_unit\_id\_foreign|unit\_id|units(id)|RESTRICT|NO ACTION|

<a id="table-product\\\_bundle\\\_components"></a>

### product\_bundle\_components（セット構成）

セットと単品の対応、1セットあたりの構成数量。

定義元：[2026\_10\_22\_010000\_create\_bundle\_components\_and\_order\_backorders.php](database/migrations/2026_10_22_010000_create_bundle_components_and_order_backorders.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|bundle\_product\_id|セット商品ID|bigint unsigned|不可|なし|FK|
|component\_product\_id|構成単品ID|bigint unsigned|不可|なし|FK|
|quantity|1セットあたりの構成数量|decimal(19,4)|不可|なし|—|
|sort\_order|表示順|smallint unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|bundle\_component\_unique|bundle\_product\_id, component\_product\_id|UNIQUE|btree|
|primary|id|PRIMARY KEY|btree|
|product\_bundle\_components\_component\_product\_id\_foreign|component\_product\_id|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|product\_bundle\_components\_bundle\_product\_id\_foreign|bundle\_product\_id|products(id)|CASCADE|NO ACTION|
|product\_bundle\_components\_component\_product\_id\_foreign|component\_product\_id|products(id)|RESTRICT|NO ACTION|

<a id="table-suppliers"></a>

### suppliers（仕入先）

仕入先の連絡先・状態。

定義元：[2026\_10\_21\_020308\_create\_suppliers\_and\_product\_supplier\_tables.php](database/migrations/2026_10_21_020308_create_suppliers_and_product_supplier_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|code|コード|varchar(50)|不可|なし|UQ|
|name|名称|varchar(150)|不可|なし|—|
|contact\_name|担当者名|varchar(100)|可|NULL|—|
|email|メールアドレス|varchar(255)|可|NULL|—|
|phone|電話番号|varchar(50)|可|NULL|—|
|postal\_code|郵便番号|varchar(20)|可|NULL|—|
|address|住所|varchar(500)|可|NULL|—|
|notes|備考|text|可|NULL|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|suppliers\_code\_unique|code|UNIQUE|btree|
|suppliers\_status\_name\_index|status, name|INDEX|btree|

**外部キー**

なし。

<a id="table-product\\\_suppliers"></a>

### product\_suppliers（商品仕入条件）

仕入先と単品ごとの単価・納期・通常仕入先設定。

定義元：[2026\_10\_21\_020308\_create\_suppliers\_and\_product\_supplier\_tables.php](database/migrations/2026_10_21_020308_create_suppliers_and_product_supplier_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|supplier\_id|仕入先ID|bigint unsigned|不可|なし|FK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|supplier\_product\_code|仕入先側の商品コード|varchar(100)|可|NULL|—|
|default\_purchase\_price|基準仕入単価|decimal(19,4)|可|NULL|—|
|minimum\_order\_quantity|最小発注数量|decimal(19,4)|可|NULL|—|
|lead\_time\_days|標準納期（日）|smallint unsigned|可|NULL|—|
|is\_primary|通常仕入先フラグ|tinyint(1)|不可|0|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|product\_supplier\_primary\_lookup|product\_id, is\_primary, status|INDEX|btree|
|product\_suppliers\_supplier\_id\_product\_id\_unique|supplier\_id, product\_id|UNIQUE|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|product\_suppliers\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|
|product\_suppliers\_supplier\_id\_foreign|supplier\_id|suppliers(id)|RESTRICT|NO ACTION|

<a id="table-purchase\\\_receipts"></a>

### purchase\_receipts（仕入入庫伝票）

入庫のヘッダ。下書きと確定を管理。

定義元：[2026\_10\_21\_022437\_create\_purchase\_receipts\_and\_inventory\_tables.php](database/migrations/2026_10_21_022437_create_purchase_receipts_and_inventory_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|receipt\_number|入庫伝票番号（R-＋ULID）|varchar(50)|不可|なし|UQ|
|supplier\_id|仕入先ID|bigint unsigned|不可|なし|FK|
|received\_on|入庫日|date|不可|なし|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|draft|—|
|notes|備考|text|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|posted\_by|入庫確定ユーザーID|bigint unsigned|可|NULL|FK|
|posted\_at|入庫確定日時|datetime(6)|可|NULL|—|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|purchase\_receipts\_created\_by\_foreign|created\_by|INDEX|btree|
|purchase\_receipts\_posted\_by\_foreign|posted\_by|INDEX|btree|
|purchase\_receipts\_receipt\_number\_unique|receipt\_number|UNIQUE|btree|
|purchase\_receipts\_status\_received\_on\_index|status, received\_on|INDEX|btree|
|purchase\_receipts\_supplier\_id\_received\_on\_index|supplier\_id, received\_on|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|purchase\_receipts\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|
|purchase\_receipts\_posted\_by\_foreign|posted\_by|users(id)|RESTRICT|NO ACTION|
|purchase\_receipts\_supplier\_id\_foreign|supplier\_id|suppliers(id)|RESTRICT|NO ACTION|

<a id="table-purchase\\\_receipt\\\_lines"></a>

### purchase\_receipt\_lines（仕入入庫明細）

商品、ロット番号、数量、単価。確定時にロットを生成。

定義元：[2026\_10\_21\_022437\_create\_purchase\_receipts\_and\_inventory\_tables.php](database/migrations/2026_10_21_022437_create_purchase_receipts_and_inventory_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|purchase\_receipt\_id|入庫伝票ID|bigint unsigned|不可|なし|FK|
|line\_number|伝票内の明細番号|smallint unsigned|不可|なし|—|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|product\_supplier\_id|商品仕入条件ID|bigint unsigned|不可|なし|FK|
|lot\_number|ロット番号|varchar(100)|不可|なし|—|
|manufactured\_on|製造日|date|可|NULL|—|
|expires\_on|期限日|date|可|NULL|—|
|quantity|数量|decimal(19,4)|不可|なし|—|
|unit\_purchase\_price|仕入単価|decimal(19,4)|不可|なし|—|
|purchase\_amount|入庫金額（数量×仕入単価）|decimal(19,4)|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|purchase\_receipt\_line\_number\_unique|purchase\_receipt\_id, line\_number|UNIQUE|btree|
|purchase\_receipt\_lines\_product\_id\_lot\_number\_index|product\_id, lot\_number|INDEX|btree|
|purchase\_receipt\_lines\_product\_supplier\_id\_foreign|product\_supplier\_id|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|purchase\_receipt\_lines\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|
|purchase\_receipt\_lines\_product\_supplier\_id\_foreign|product\_supplier\_id|product\_suppliers(id)|RESTRICT|NO ACTION|
|purchase\_receipt\_lines\_purchase\_receipt\_id\_foreign|purchase\_receipt\_id|purchase\_receipts(id)|CASCADE|NO ACTION|

<a id="table-inventory\\\_lots"></a>

### inventory\_lots（ロット在庫）

入庫明細ごとの残量。受注引当で減るため物理実在庫とは異なる。

定義元：[2026\_10\_21\_022437\_create\_purchase\_receipts\_and\_inventory\_tables.php](database/migrations/2026_10_21_022437_create_purchase_receipts_and_inventory_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|supplier\_id|仕入先ID|bigint unsigned|不可|なし|FK|
|purchase\_receipt\_line\_id|入庫明細ID|bigint unsigned|不可|なし|UQ, FK|
|lot\_number|ロット番号|varchar(100)|不可|なし|—|
|received\_on|入庫日|date|不可|なし|—|
|manufactured\_on|製造日|date|可|NULL|—|
|expires\_on|期限日|date|可|NULL|—|
|initial\_quantity|初回入庫数量|decimal(19,4)|不可|なし|—|
|current\_quantity|現在数量（引当済み分を除く）|decimal(19,4)|不可|なし|—|
|unit\_purchase\_price|仕入単価|decimal(19,4)|不可|なし|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|active|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|inventory\_lot\_product\_lookup|product\_id, status, received\_on|INDEX|btree|
|inventory\_lots\_lot\_number\_index|lot\_number|INDEX|btree|
|inventory\_lots\_purchase\_receipt\_line\_id\_unique|purchase\_receipt\_line\_id|UNIQUE|btree|
|inventory\_lots\_supplier\_id\_received\_on\_index|supplier\_id, received\_on|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|inventory\_lots\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|
|inventory\_lots\_purchase\_receipt\_line\_id\_foreign|purchase\_receipt\_line\_id|purchase\_receipt\_lines(id)|RESTRICT|NO ACTION|
|inventory\_lots\_supplier\_id\_foreign|supplier\_id|suppliers(id)|RESTRICT|NO ACTION|

<a id="table-stock\\\_movements"></a>

### stock\_movements（在庫移動履歴）

入庫・出庫・調整・取消戻入・再引当の増減記録。

定義元：[2026\_10\_21\_022437\_create\_purchase\_receipts\_and\_inventory\_tables.php](database/migrations/2026_10_21_022437_create_purchase_receipts_and_inventory_tables.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|inventory\_lot\_id|在庫ロットID|bigint unsigned|可|NULL|FK|
|movement\_type|在庫移動区分|varchar(30)|不可|なし|—|
|occurred\_on|処理日|date|不可|なし|—|
|quantity\_delta|在庫増減量（正＝増加、負＝減少）|decimal(19,4)|不可|なし|—|
|unit\_cost|処理時の原価単価|decimal(19,4)|可|NULL|—|
|source\_type|発生元種別（purchase\_receipt\_line/stock\_operation\_line）|varchar(50)|不可|なし|—|
|source\_id|発生元明細ID。種別と組合せ参照、DB外部キーなし|bigint unsigned|不可|なし|—|
|notes|備考|varchar(500)|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|stock\_movement\_source\_unique|source\_type, source\_id|UNIQUE|btree|
|stock\_movements\_created\_by\_foreign|created\_by|INDEX|btree|
|stock\_movements\_inventory\_lot\_id\_foreign|inventory\_lot\_id|INDEX|btree|
|stock\_movements\_movement\_type\_occurred\_on\_index|movement\_type, occurred\_on|INDEX|btree|
|stock\_movements\_product\_id\_occurred\_on\_index|product\_id, occurred\_on|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|stock\_movements\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|
|stock\_movements\_inventory\_lot\_id\_foreign|inventory\_lot\_id|inventory\_lots(id)|RESTRICT|NO ACTION|
|stock\_movements\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|

<a id="table-stock\\\_operations"></a>

### stock\_operations（在庫操作）

手動出庫・調整、受注出庫、取消戻入、再引当のヘッダ。

定義元：[2026\_10\_21\_024023\_create\_stock\_operations\_table.php](database/migrations/2026_10_21_024023_create_stock_operations_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|operation\_number|在庫操作番号（SO-＋ULID）|varchar(50)|不可|なし|UQ|
|operation\_type|在庫操作区分|varchar(20)|不可|なし|—|
|occurred\_on|処理日|date|不可|なし|—|
|notes|備考|text|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|stock\_operations\_created\_by\_foreign|created\_by|INDEX|btree|
|stock\_operations\_operation\_number\_unique|operation\_number|UNIQUE|btree|
|stock\_operations\_operation\_type\_occurred\_on\_index|operation\_type, occurred\_on|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|stock\_operations\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|

<a id="table-stock\\\_operation\\\_lines"></a>

### stock\_operation\_lines（在庫操作明細）

ロットごとの変更前・増減・変更後数量。

定義元：[2026\_10\_21\_024023\_create\_stock\_operations\_table.php](database/migrations/2026_10_21_024023_create_stock_operations_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|stock\_operation\_id|在庫操作ID|bigint unsigned|不可|なし|FK|
|line\_number|伝票内の明細番号|smallint unsigned|不可|なし|—|
|inventory\_lot\_id|在庫ロットID|bigint unsigned|不可|なし|FK|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|quantity\_delta|在庫増減量（正＝増加、負＝減少）|decimal(19,4)|不可|なし|—|
|quantity\_before|処理前数量|decimal(19,4)|不可|なし|—|
|quantity\_after|処理後数量|decimal(19,4)|不可|なし|—|
|unit\_cost|処理時の原価単価|decimal(19,4)|可|NULL|—|
|reason|理由|varchar(500)|可|NULL|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|stock\_operation\_line\_number\_unique|stock\_operation\_id, line\_number|UNIQUE|btree|
|stock\_operation\_lines\_inventory\_lot\_id\_created\_at\_index|inventory\_lot\_id, created\_at|INDEX|btree|
|stock\_operation\_lines\_product\_id\_created\_at\_index|product\_id, created\_at|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|stock\_operation\_lines\_inventory\_lot\_id\_foreign|inventory\_lot\_id|inventory\_lots(id)|RESTRICT|NO ACTION|
|stock\_operation\_lines\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|
|stock\_operation\_lines\_stock\_operation\_id\_foreign|stock\_operation\_id|stock\_operations(id)|CASCADE|NO ACTION|

<a id="table-orders"></a>

### orders（注文）

顧客、受付元、金額、状態、確定・取消情報。

定義元：[2026\_10\_21\_025233\_create\_orders\_tables.php](database/migrations/2026_10_21_025233_create_orders_tables.php)、[2026\_10\_21\_040000\_add\_order\_lifecycle\_and\_shortage\_policy.php](database/migrations/2026_10_21_040000_add_order_lifecycle_and_shortage_policy.php)、[2026\_10\_21\_050000\_add\_backorder\_fulfillment\_tracking.php](database/migrations/2026_10_21_050000_add_backorder_fulfillment_tracking.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|order\_number|注文番号（O-＋ULID）|varchar(50)|不可|なし|UQ|
|customer\_id|顧客ユーザーID|bigint unsigned|不可|なし|FK|
|order\_source|受付元（admin/customer）|varchar(20)|不可|admin|—|
|ordered\_on|注文日|date|不可|なし|—|
|status|状態（第4章の値域参照）|varchar(20)|不可|draft|—|
|subtotal|明細金額合計|decimal(19,4)|不可|0.0000|—|
|total|注文合計（現状subtotalと同値）|decimal(19,4)|不可|0.0000|—|
|notes|備考|text|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|confirmed\_by|確定ユーザーID|bigint unsigned|可|NULL|FK|
|confirmed\_at|確定日時|datetime(6)|可|NULL|—|
|backorder\_completed\_at|注文全体の取り寄せ解消日時|datetime(6)|可|NULL|—|
|cancelled\_by|取消ユーザーID|bigint unsigned|可|NULL|FK|
|cancelled\_at|取消日時|datetime(6)|可|NULL|—|
|cancellation\_reason|取消理由|varchar(500)|可|NULL|—|
|stock\_operation\_id|初回受注確定時の在庫操作ID。全量欠品ではNULL|bigint unsigned|可|NULL|UQ, FK|
|cancellation\_stock\_operation\_id|取消時の在庫戻入操作ID|bigint unsigned|可|NULL|UQ, FK|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|orders\_cancellation\_stock\_operation\_id\_unique|cancellation\_stock\_operation\_id|UNIQUE|btree|
|orders\_cancelled\_by\_foreign|cancelled\_by|INDEX|btree|
|orders\_confirmed\_by\_foreign|confirmed\_by|INDEX|btree|
|orders\_created\_by\_foreign|created\_by|INDEX|btree|
|orders\_customer\_id\_ordered\_on\_index|customer\_id, ordered\_on|INDEX|btree|
|orders\_order\_number\_unique|order\_number|UNIQUE|btree|
|orders\_status\_ordered\_on\_index|status, ordered\_on|INDEX|btree|
|orders\_stock\_operation\_id\_unique|stock\_operation\_id|UNIQUE|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|orders\_cancellation\_stock\_operation\_id\_foreign|cancellation\_stock\_operation\_id|stock\_operations(id)|RESTRICT|NO ACTION|
|orders\_cancelled\_by\_foreign|cancelled\_by|users(id)|RESTRICT|NO ACTION|
|orders\_confirmed\_by\_foreign|confirmed\_by|users(id)|RESTRICT|NO ACTION|
|orders\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|
|orders\_customer\_id\_foreign|customer\_id|users(id)|RESTRICT|NO ACTION|
|orders\_stock\_operation\_id\_foreign|stock\_operation\_id|stock\_operations(id)|RESTRICT|NO ACTION|

<a id="table-order\\\_lines"></a>

### order\_lines（注文明細）

販売商品・単価・単位名のスナップショットと数量。

定義元：[2026\_10\_21\_025233\_create\_orders\_tables.php](database/migrations/2026_10_21_025233_create_orders_tables.php)、[2026\_10\_21\_040000\_add\_order\_lifecycle\_and\_shortage\_policy.php](database/migrations/2026_10_21_040000_add_order_lifecycle_and_shortage_policy.php)、[2026\_10\_21\_050000\_add\_backorder\_fulfillment\_tracking.php](database/migrations/2026_10_21_050000_add_backorder_fulfillment_tracking.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|order\_id|注文ID|bigint unsigned|不可|なし|FK|
|line\_number|伝票内の明細番号|smallint unsigned|不可|なし|—|
|product\_id|販売商品ID|bigint unsigned|不可|なし|FK|
|product\_sku|受注時の商品コード|varchar(50)|不可|なし|—|
|product\_name|受注時の商品名|varchar(200)|不可|なし|—|
|unit\_name|記録時の単位名|varchar(30)|不可|なし|—|
|quantity|数量|decimal(19,4)|不可|なし|—|
|backordered\_initial\_quantity|単品の初回欠品数量（互換項目）|decimal(19,4)|不可|0.0000|—|
|backordered\_quantity|単品の未引当残数量（互換項目）|decimal(19,4)|不可|0.0000|—|
|backorder\_fulfilled\_at|単品の欠品解消日時（互換項目）|datetime(6)|可|NULL|—|
|unit\_sale\_price|受注時の販売単価|decimal(19,4)|不可|なし|—|
|line\_amount|明細金額（数量×販売単価）|decimal(19,4)|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|order\_line\_number\_unique|order\_id, line\_number|UNIQUE|btree|
|order\_lines\_backorder\_index|product\_id, backordered\_quantity|INDEX|btree|
|order\_lines\_product\_id\_created\_at\_index|product\_id, created\_at|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|order\_lines\_order\_id\_foreign|order\_id|orders(id)|CASCADE|NO ACTION|
|order\_lines\_product\_id\_foreign|product\_id|products(id)|RESTRICT|NO ACTION|

<a id="table-order\\\_line\\\_components"></a>

### order\_line\_components（注文セット構成）

受注確定時のセット構成スナップショット。

定義元：[2026\_10\_22\_010000\_create\_bundle\_components\_and\_order\_backorders.php](database/migrations/2026_10_22_010000_create_bundle_components_and_order_backorders.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|order\_line\_id|注文明細ID|bigint unsigned|不可|なし|FK|
|component\_product\_id|構成単品ID|bigint unsigned|不可|なし|FK|
|component\_sku|確定時の構成品コード|varchar(50)|不可|なし|—|
|component\_name|確定時の構成品名|varchar(200)|不可|なし|—|
|unit\_name|記録時の単位名|varchar(30)|不可|なし|—|
|quantity\_per\_set|1セットあたりの数量|decimal(19,4)|不可|なし|—|
|required\_quantity|必要総数量|decimal(19,4)|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|order\_line\_component\_unique|order\_line\_id, component\_product\_id|UNIQUE|btree|
|order\_line\_components\_component\_product\_id\_foreign|component\_product\_id|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|order\_line\_components\_component\_product\_id\_foreign|component\_product\_id|products(id)|RESTRICT|NO ACTION|
|order\_line\_components\_order\_line\_id\_foreign|order\_line\_id|order\_lines(id)|CASCADE|NO ACTION|

<a id="table-order\\\_backorders"></a>

### order\_backorders（取り寄せ）

単品またはセット構成品単位の不足と解消履歴。

定義元：[2026\_10\_22\_010000\_create\_bundle\_components\_and\_order\_backorders.php](database/migrations/2026_10_22_010000_create_bundle_components_and_order_backorders.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|order\_line\_id|注文明細ID|bigint unsigned|不可|なし|FK|
|order\_line\_component\_id|注文セット構成ID。単品ではNULL|bigint unsigned|可|NULL|FK|
|stock\_product\_id|実際に在庫を引き当てる単品ID|bigint unsigned|不可|なし|FK|
|stock\_product\_sku|在庫対象単品のコード|varchar(50)|不可|なし|—|
|stock\_product\_name|在庫対象単品の名称|varchar(200)|不可|なし|—|
|unit\_name|記録時の単位名|varchar(30)|不可|なし|—|
|required\_quantity|注文数量または構成品必要総数量|decimal(19,4)|不可|なし|—|
|initial\_quantity|初回欠品数量|decimal(19,4)|不可|なし|—|
|remaining\_quantity|未引当残数量|decimal(19,4)|不可|なし|—|
|fulfilled\_at|不足解消日時|datetime(6)|可|NULL|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|order\_backorders\_order\_line\_component\_id\_foreign|order\_line\_component\_id|INDEX|btree|
|order\_backorders\_order\_line\_id\_fulfilled\_at\_index|order\_line\_id, fulfilled\_at|INDEX|btree|
|order\_backorders\_stock\_remaining\_index|stock\_product\_id, remaining\_quantity|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|order\_backorders\_order\_line\_component\_id\_foreign|order\_line\_component\_id|order\_line\_components(id)|CASCADE|NO ACTION|
|order\_backorders\_order\_line\_id\_foreign|order\_line\_id|order\_lines(id)|CASCADE|NO ACTION|
|order\_backorders\_stock\_product\_id\_foreign|stock\_product\_id|products(id)|RESTRICT|NO ACTION|

<a id="table-order\\\_allocations"></a>

### order\_allocations（注文引当）

注文と在庫ロット・在庫操作明細の対応。

定義元：[2026\_10\_21\_025233\_create\_orders\_tables.php](database/migrations/2026_10_21_025233_create_orders_tables.php)、[2026\_10\_21\_050000\_add\_backorder\_fulfillment\_tracking.php](database/migrations/2026_10_21_050000_add_backorder_fulfillment_tracking.php)、[2026\_10\_22\_010000\_create\_bundle\_components\_and\_order\_backorders.php](database/migrations/2026_10_22_010000_create_bundle_components_and_order_backorders.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|order\_line\_id|注文明細ID|bigint unsigned|不可|なし|FK|
|order\_line\_component\_id|注文セット構成ID。単品ではNULL|bigint unsigned|可|NULL|FK|
|order\_backorder\_id|取り寄せID。欠品がなければNULL|bigint unsigned|可|NULL|FK|
|inventory\_lot\_id|在庫ロットID|bigint unsigned|不可|なし|FK|
|stock\_operation\_line\_id|在庫操作明細ID|bigint unsigned|不可|なし|UQ, FK|
|quantity|今回の引当数量|decimal(19,4)|不可|なし|—|
|unit\_cost|処理時の原価単価|decimal(19,4)|可|NULL|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|order\_allocation\_line\_lot\_index|order\_line\_id, inventory\_lot\_id|INDEX|btree|
|order\_allocations\_inventory\_lot\_id\_created\_at\_index|inventory\_lot\_id, created\_at|INDEX|btree|
|order\_allocations\_order\_backorder\_id\_foreign|order\_backorder\_id|INDEX|btree|
|order\_allocations\_order\_line\_component\_id\_foreign|order\_line\_component\_id|INDEX|btree|
|order\_allocations\_stock\_operation\_line\_id\_unique|stock\_operation\_line\_id|UNIQUE|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|order\_allocations\_inventory\_lot\_id\_foreign|inventory\_lot\_id|inventory\_lots(id)|RESTRICT|NO ACTION|
|order\_allocations\_order\_backorder\_id\_foreign|order\_backorder\_id|order\_backorders(id)|RESTRICT|NO ACTION|
|order\_allocations\_order\_line\_component\_id\_foreign|order\_line\_component\_id|order\_line\_components(id)|RESTRICT|NO ACTION|
|order\_allocations\_order\_line\_id\_foreign|order\_line\_id|order\_lines(id)|CASCADE|NO ACTION|
|order\_allocations\_stock\_operation\_line\_id\_foreign|stock\_operation\_line\_id|stock\_operation\_lines(id)|RESTRICT|NO ACTION|

<a id="table-order\\\_settings"></a>

### order\_settings（注文設定）

取消可能状態のJSON配列。アプリはid=1を利用。

定義元：[2026\_10\_21\_040000\_add\_order\_lifecycle\_and\_shortage\_policy.php](database/migrations/2026_10_21_040000_add_order_lifecycle_and_shortage_policy.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|cancellable\_statuses|取消可能注文状態の配列|json|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-shipments"></a>

### shipments（発送伝票）

注文単位の配送情報と発送・取消状態。

定義元：[2026\_10\_22\_025536\_create\_shipments\_table.php](database/migrations/2026_10_22_025536_create_shipments_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|shipment\_number|発送番号（S-＋ULID）|varchar(50)|不可|なし|UQ|
|order\_id|注文ID|bigint unsigned|不可|なし|FK|
|status|状態（第4章の値域参照）|varchar(20)|不可|draft|—|
|carrier|配送会社|varchar(100)|可|NULL|—|
|tracking\_number|追跡番号|varchar(100)|可|NULL|—|
|scheduled\_on|発送予定日|date|可|NULL|—|
|shipped\_on|発送日|date|可|NULL|—|
|notes|備考|text|可|NULL|—|
|created\_by|作成ユーザーID|bigint unsigned|不可|なし|FK|
|shipped\_by|発送確定ユーザーID|bigint unsigned|可|NULL|FK|
|shipped\_at|発送確定日時|datetime(6)|可|NULL|—|
|cancelled\_by|取消ユーザーID|bigint unsigned|可|NULL|FK|
|cancelled\_at|取消日時|datetime(6)|可|NULL|—|
|cancellation\_reason|取消理由|varchar(500)|可|NULL|—|
|lock\_version|編集競合検出用の版番号|int unsigned|不可|0|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|shipments\_cancelled\_by\_foreign|cancelled\_by|INDEX|btree|
|shipments\_created\_by\_foreign|created\_by|INDEX|btree|
|shipments\_order\_id\_status\_index|order\_id, status|INDEX|btree|
|shipments\_shipment\_number\_unique|shipment\_number|UNIQUE|btree|
|shipments\_shipped\_by\_foreign|shipped\_by|INDEX|btree|
|shipments\_status\_scheduled\_on\_index|status, scheduled\_on|INDEX|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|shipments\_cancelled\_by\_foreign|cancelled\_by|users(id)|RESTRICT|NO ACTION|
|shipments\_created\_by\_foreign|created\_by|users(id)|RESTRICT|NO ACTION|
|shipments\_order\_id\_foreign|order\_id|orders(id)|RESTRICT|NO ACTION|
|shipments\_shipped\_by\_foreign|shipped\_by|users(id)|RESTRICT|NO ACTION|

<a id="table-shipment\\\_lines"></a>

### shipment\_lines（発送明細）

注文明細ごとの発送数量。分割発送を許容。

定義元：[2026\_10\_22\_025536\_create\_shipments\_table.php](database/migrations/2026_10_22_025536_create_shipments_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|shipment\_id|発送伝票ID|bigint unsigned|不可|なし|FK|
|order\_line\_id|注文明細ID|bigint unsigned|不可|なし|FK|
|quantity|今回の発送数量|decimal(19,4)|不可|なし|—|
|created\_at|作成日時|timestamp(6)|可|NULL|—|
|updated\_at|更新日時|timestamp(6)|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|
|shipment\_lines\_order\_line\_id\_index|order\_line\_id|INDEX|btree|
|shipment\_lines\_shipment\_id\_order\_line\_id\_unique|shipment\_id, order\_line\_id|UNIQUE|btree|

**外部キー**

|制約名|列|参照先|ON DELETE|ON UPDATE|
|-|-|-|-|-|
|shipment\_lines\_order\_line\_id\_foreign|order\_line\_id|order\_lines(id)|RESTRICT|NO ACTION|
|shipment\_lines\_shipment\_id\_foreign|shipment\_id|shipments(id)|CASCADE|NO ACTION|

<a id="table-cache"></a>

### cache（キャッシュ）

databaseキャッシュストア用。存在と利用ドライバは別。

定義元：[0001\_01\_01\_000001\_create\_cache\_table.php](database/migrations/0001_01_01_000001_create_cache_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|key|キー|varchar(255)|不可|なし|PK|
|value|値|mediumtext|不可|なし|—|
|expiration|有効期限（Unix秒）|bigint|不可|なし|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|cache\_expiration\_index|expiration|INDEX|btree|
|primary|key|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-cache\\\_locks"></a>

### cache\_locks（キャッシュロック）

databaseストアの分散ロック用。

定義元：[0001\_01\_01\_000001\_create\_cache\_table.php](database/migrations/0001_01_01_000001_create_cache_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|key|キー|varchar(255)|不可|なし|PK|
|owner|ロック所有者|varchar(255)|不可|なし|—|
|expiration|有効期限（Unix秒）|bigint|不可|なし|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|cache\_locks\_expiration\_index|expiration|INDEX|btree|
|primary|key|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-jobs"></a>

### jobs（キュージョブ）

databaseキュードライバ用の待機ジョブ。

定義元：[0001\_01\_01\_000002\_create\_jobs\_table.php](database/migrations/0001_01_01_000002_create_jobs_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|queue|キュー名|varchar(255)|不可|なし|—|
|payload|シリアライズされた内容|longtext|不可|なし|—|
|attempts|試行回数|smallint unsigned|不可|なし|—|
|reserved\_at|予約時刻（Unix秒）|int unsigned|可|NULL|—|
|available\_at|実行可能時刻（Unix秒）|int unsigned|不可|なし|—|
|created\_at|作成時刻（Unix秒）|int unsigned|不可|なし|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|jobs\_queue\_index|queue|INDEX|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-job\\\_batches"></a>

### job\_batches（ジョブバッチ）

バッチの進捗・失敗・取消情報。

定義元：[0001\_01\_01\_000002\_create\_jobs\_table.php](database/migrations/0001_01_01_000002_create_jobs_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|varchar(255)|不可|なし|PK|
|name|名称|varchar(255)|不可|なし|—|
|total\_jobs|総ジョブ数|int|不可|なし|—|
|pending\_jobs|待機ジョブ数|int|不可|なし|—|
|failed\_jobs|失敗ジョブ数|int|不可|なし|—|
|failed\_job\_ids|失敗ジョブID一覧|longtext|不可|なし|—|
|options|バッチオプション|mediumtext|可|NULL|—|
|cancelled\_at|取消時刻（Unix秒）|int|可|NULL|—|
|created\_at|作成時刻（Unix秒）|int|不可|なし|—|
|finished\_at|完了時刻（Unix秒）|int|可|NULL|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-failed\\\_jobs"></a>

### failed\_jobs（失敗ジョブ）

失敗したジョブの内容と例外。

定義元：[0001\_01\_01\_000002\_create\_jobs\_table.php](database/migrations/0001_01_01_000002_create_jobs_table.php)。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|bigint unsigned|不可|なし／自動採番|PK|
|uuid|ジョブ識別UUID|varchar(255)|不可|なし|UQ|
|connection|接続名|varchar(255)|不可|なし|—|
|queue|キュー名|varchar(255)|不可|なし|—|
|payload|シリアライズされた内容|longtext|不可|なし|—|
|exception|例外内容|longtext|不可|なし|—|
|failed\_at|失敗日時|timestamp|不可|CURRENT\_TIMESTAMP|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|failed\_jobs\_connection\_queue\_failed\_at\_index|connection, queue, failed\_at|INDEX|btree|
|failed\_jobs\_uuid\_unique|uuid|UNIQUE|btree|
|primary|id|PRIMARY KEY|btree|

**外部キー**

なし。

<a id="table-migrations"></a>

### migrations（マイグレーション履歴）

Laravelの適用履歴。業務モデルではない。

定義元：Laravelのマイグレーション管理機構。実DBから確認。

|カラム名|論理名・説明|型・桁数|NULL可|DB既定値|キー|
|-|-|-|-|-|-|
|id|識別子|int unsigned|不可|なし／自動採番|PK|
|migration|適用マイグレーション名|varchar(255)|不可|なし|—|
|batch|適用バッチ番号|int|不可|なし|—|

**索引**

|名前|列（順序どおり）|種別|方式|
|-|-|-|-|
|primary|id|PRIMARY KEY|btree|

**外部キー**

なし。

## 6\. 後続マイグレーションの反映

|変更|最終定義|
|-|-|
|権限|users.role追加、既定customer|
|属性管理|products.product\_group\_id、基本商品・定義・値テーブル追加|
|注文ライフサイクル|不足方針、注文取消情報、order\_settings、明細欠品数量|
|取り寄せ追跡|明細初回欠品数量・解消日時、注文全体の解消日時|
|再引当|引当の明細＋ロット一意制約を削除し通常索引に変更|
|セット|構成マスタ、注文構成スナップショット、order\_backorders、引当参照追加|
|発送|shipments、shipment\_lines追加|

order\_linesの欠品項目は単品の互換情報として残存。セットの欠品はorder\_backordersを参照。初回作成マイグレーションだけでは最終形を再現できない。

## 7\. 更新・運用上の確認事項

* 入庫確定：ロット・inbound履歴作成、取り寄せ再引当をトランザクションで処理。
* 受注確定：必要量・構成スナップショット・欠品記録・在庫操作・引当・注文確定を一括処理。
* 発送確定：発送実績と注文状態を更新。在庫再減算なし。
* 取消：引当履歴を保持して元ロットへ戻入。集計時は取消済み伝票を考慮。
* 通貨列・税・送料・決済・配送先テーブルはない。必要なら追加要件定義。
* 期限切れロットの引当排除は現行処理にない。
* 在庫操作備考は入力2000文字、転記先stock\_movements.notesはVARCHAR(500)。長文処理は要確認。
* 削除・アーカイブ・バックアップ・保存期間・暗号化・本番との差分確認は別途運用設計が必要。
* 本書作成のためDDL変更、マイグレーション適用、Seeder、業務データ更新は行っていない。
* 機能テストは実行していない。業務ルールは[基本設計書](BASIC_DESIGN.md)参照。

## 8\. 改訂履歴

|版|日付|内容|
|-|-|-|
|1.0|2026-10-23|開発DB全構造と適用済みマイグレーションに基づき初版作成|



