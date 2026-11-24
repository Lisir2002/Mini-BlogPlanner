<?php

declare(strict_types=1);

return [
    'emoji' => [
        'label' => 'Emoji',
        'short' => '文字表情',
        'kind' => 'emoji',
        'groups' => [
            'reply' => [
                'label' => '回应',
                'hint' => '先接话，态度明确',
                'items' => [
                    ['value' => '👍', 'name' => '点赞'],
                    ['value' => '👏', 'name' => '鼓掌'],
                    ['value' => '🙌', 'name' => '叫好'],
                    ['value' => '🤝', 'name' => '握手'],
                    ['value' => '✅', 'name' => '可以'],
                    ['value' => '💯', 'name' => '满分'],
                ],
            ],
            'mood' => [
                'label' => '心情',
                'hint' => '一句话里的情绪底色',
                'items' => [
                    ['value' => '😄', 'name' => '开心'],
                    ['value' => '😂', 'name' => '好笑'],
                    ['value' => '🥹', 'name' => '感动'],
                    ['value' => '😭', 'name' => '大哭'],
                    ['value' => '😤', 'name' => '不服'],
                    ['value' => '😮‍💨', 'name' => '叹气'],
                ],
            ],
            'daily' => [
                'label' => '日常',
                'hint' => '吃饭、睡觉、下班',
                'items' => [
                    ['value' => '🍚', 'name' => '干饭'],
                    ['value' => '☕', 'name' => '喝口'],
                    ['value' => '🌙', 'name' => '晚安'],
                    ['value' => '💤', 'name' => '困了'],
                    ['value' => '💻', 'name' => '上班'],
                    ['value' => '🎉', 'name' => '下班'],
                ],
            ],
            'mark' => [
                'label' => '符号',
                'hint' => '给关键词加个重音',
                'items' => [
                    ['value' => '❤️', 'name' => '喜欢'],
                    ['value' => '✨', 'name' => '闪光'],
                    ['value' => '🔥', 'name' => '火了'],
                    ['value' => '⭐', 'name' => '收藏'],
                    ['value' => '📌', 'name' => '记一下'],
                    ['value' => '🎯', 'name' => '到位'],
                ],
            ],
        ],
    ],
    'sticker' => [
        'label' => '贴图',
        'short' => 'GIF 动图',
        'kind' => 'sticker',
        'credit' => '动图来自 Noto Emoji，CC BY 4.0',
        'groups' => [
            'reply' => [
                'label' => '回应',
                'hint' => '动作比文字更快',
                'items' => [
                    ['name' => '点赞', 'slug' => 'like'],
                    ['name' => '鼓掌', 'slug' => 'clap'],
                    ['name' => '举手', 'slug' => 'hands'],
                    ['name' => '握手', 'slug' => 'handshake'],
                    ['name' => '比耶', 'slug' => 'peace'],
                    ['name' => '加油', 'slug' => 'power'],
                ],
            ],
            'mood' => [
                'label' => '情绪',
                'hint' => '一张图接住整句感叹',
                'items' => [
                    ['name' => '爆笑', 'slug' => 'joy'],
                    ['name' => '感动', 'slug' => 'moved'],
                    ['name' => '大哭', 'slug' => 'cry'],
                    ['name' => '生气', 'slug' => 'angry'],
                    ['name' => '震惊', 'slug' => 'mindblown'],
                    ['name' => '无语', 'slug' => 'speechless'],
                ],
            ],
            'scene' => [
                'label' => '氛围',
                'hint' => '庆祝、晚安、喝口、好运',
                'items' => [
                    ['name' => '庆祝', 'slug' => 'party'],
                    ['name' => '比心', 'slug' => 'heart'],
                    ['name' => '火了', 'slug' => 'fire'],
                    ['name' => '晚安', 'slug' => 'night'],
                    ['name' => '喝口', 'slug' => 'coffee'],
                    ['name' => '好运', 'slug' => 'luck'],
                ],
            ],
        ],
    ],
    'kaomoji' => [
        'label' => '颜文字',
        'short' => '字符表情',
        'kind' => 'text',
        'groups' => [
            'energy' => [
                'label' => '元气',
                'hint' => '把精神头打出来',
                'items' => [
                    ['value' => '(≧▽≦)', 'name' => '开心'],
                    ['value' => '(๑•̀ㅂ•́)و✧', 'name' => '加油'],
                    ['value' => '(•̀ᴗ•́)و', 'name' => '努力'],
                    ['value' => '٩(ˊᗜˋ*)و', 'name' => '冲刺'],
                    ['value' => '＼(^o^)／', 'name' => '欢呼'],
                    ['value' => '(ﾉ◕ヮ◕)ﾉ*:･ﾟ✧', 'name' => '撒花'],
                ],
            ],
            'soft' => [
                'label' => '温柔',
                'hint' => '语气放软一点',
                'items' => [
                    ['value' => '(´･ω･`)', 'name' => '委屈'],
                    ['value' => '(づ｡◕‿‿◕｡)づ', 'name' => '抱抱'],
                    ['value' => '(๑>؂<๑)', 'name' => '害羞'],
                    ['value' => '(⁄ ⁄•⁄ω⁄•⁄ ⁄)', 'name' => '脸热'],
                    ['value' => '(´ ▽ `)ﾉ', 'name' => '打招呼'],
                    ['value' => '(っ˘з(˘⌣˘ )', 'name' => '贴贴'],
                ],
            ],
            'speechless' => [
                'label' => '无语',
                'hint' => '满脸写着一个问号',
                'items' => [
                    ['value' => '(・_・;)', 'name' => '出汗'],
                    ['value' => '(；一_一)', 'name' => '无语'],
                    ['value' => '(￣▽￣)', 'name' => '轻松'],
                    ['value' => '┐(￣ヘ￣)┌', 'name' => '摊手'],
                    ['value' => '(－_－)', 'name' => '沉默'],
                    ['value' => '(°ー°〃)', 'name' => '愣住'],
                ],
            ],
            'flat' => [
                'label' => '躺平',
                'hint' => '今天先到这里',
                'items' => [
                    ['value' => 'orz', 'name' => '趴了'],
                    ['value' => '_(:3」∠)_', 'name' => '躺平'],
                    ['value' => '_(┐「ε:)_', 'name' => '瘫倒'],
                    ['value' => '(¦3[▓▓]', 'name' => '躺床'],
                    ['value' => '(=_=)', 'name' => '没电'],
                    ['value' => '(￣o￣) . z Z', 'name' => '睡着'],
                ],
            ],
        ],
    ],
];
