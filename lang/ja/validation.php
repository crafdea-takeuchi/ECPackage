<?php

return [
    'required' => ':attributeを入力してください。', 'email' => 'メールアドレスの形式を確認してください。',
    'unique' => 'この:attributeは既に登録されています。', 'confirmed' => ':attributeが確認入力と一致しません。',
    'string' => ':attributeは文字列で入力してください。', 'prohibited' => ':attributeは指定できません。',
    'in' => ':attributeの選択が正しくありません。', 'boolean' => ':attributeの値が正しくありません。',
    'min' => ['string' => ':attributeは:min文字以上で入力してください。'],
    'max' => ['string' => ':attributeは:max文字以内で入力してください。'],
    'password' => ['letters' => ':attributeには英字を含めてください。', 'numbers' => ':attributeには数字を含めてください。'],
    'attributes' => ['name' => 'お名前', 'email' => 'メールアドレス', 'password' => 'パスワード', 'role' => '権限'],
];
