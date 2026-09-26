const ROMAJI_TO_HIRAGANA: Record<string, string> = {
    kya: 'きゃ',
    kyu: 'きゅ',
    kyo: 'きょ',
    gya: 'ぎゃ',
    gyu: 'ぎゅ',
    gyo: 'ぎょ',
    sha: 'しゃ',
    shu: 'しゅ',
    sho: 'しょ',
    sya: 'しゃ',
    syu: 'しゅ',
    syo: 'しょ',
    ja: 'じゃ',
    ju: 'じゅ',
    jo: 'じょ',
    jya: 'じゃ',
    jyu: 'じゅ',
    jyo: 'じょ',
    cha: 'ちゃ',
    chu: 'ちゅ',
    cho: 'ちょ',
    tya: 'ちゃ',
    tyu: 'ちゅ',
    tyo: 'ちょ',
    nya: 'にゃ',
    nyu: 'にゅ',
    nyo: 'にょ',
    hya: 'ひゃ',
    hyu: 'ひゅ',
    hyo: 'ひょ',
    bya: 'びゃ',
    byu: 'びゅ',
    byo: 'びょ',
    pya: 'ぴゃ',
    pyu: 'ぴゅ',
    pyo: 'ぴょ',
    mya: 'みゃ',
    myu: 'みゅ',
    myo: 'みょ',
    rya: 'りゃ',
    ryu: 'りゅ',
    ryo: 'りょ',
    fa: 'ふぁ',
    fi: 'ふぃ',
    fe: 'ふぇ',
    fo: 'ふぉ',
    va: 'ゔぁ',
    vi: 'ゔぃ',
    vu: 'ゔ',
    ve: 'ゔぇ',
    vo: 'ゔぉ',
    xtsu: 'っ',
    ltsu: 'っ',
    xya: 'ゃ',
    xyu: 'ゅ',
    xyo: 'ょ',
    lya: 'ゃ',
    lyu: 'ゅ',
    lyo: 'ょ',
    shi: 'し',
    chi: 'ち',
    tsu: 'つ',
    a: 'あ',
    i: 'い',
    u: 'う',
    e: 'え',
    o: 'お',
    ka: 'か',
    ki: 'き',
    ku: 'く',
    ke: 'け',
    ko: 'こ',
    ga: 'が',
    gi: 'ぎ',
    gu: 'ぐ',
    ge: 'げ',
    go: 'ご',
    sa: 'さ',
    si: 'し',
    su: 'す',
    se: 'せ',
    so: 'そ',
    za: 'ざ',
    ji: 'じ',
    zi: 'じ',
    zu: 'ず',
    ze: 'ぜ',
    zo: 'ぞ',
    ta: 'た',
    ti: 'ち',
    tu: 'つ',
    te: 'て',
    to: 'と',
    da: 'だ',
    di: 'ぢ',
    du: 'づ',
    de: 'で',
    do: 'ど',
    na: 'な',
    ni: 'に',
    nu: 'ぬ',
    ne: 'ね',
    no: 'の',
    ha: 'は',
    hi: 'ひ',
    fu: 'ふ',
    hu: 'ふ',
    he: 'へ',
    ho: 'ほ',
    ba: 'ば',
    bi: 'び',
    bu: 'ぶ',
    be: 'べ',
    bo: 'ぼ',
    pa: 'ぱ',
    pi: 'ぴ',
    pu: 'ぷ',
    pe: 'ぺ',
    po: 'ぽ',
    ma: 'ま',
    mi: 'み',
    mu: 'む',
    me: 'め',
    mo: 'も',
    ya: 'や',
    yu: 'ゆ',
    yo: 'よ',
    ra: 'ら',
    ri: 'り',
    ru: 'る',
    re: 'れ',
    ro: 'ろ',
    wa: 'わ',
    wo: 'を',
    xa: 'ぁ',
    xi: 'ぃ',
    xu: 'ぅ',
    xe: 'ぇ',
    xo: 'ぉ',
    la: 'ぁ',
    li: 'ぃ',
    lu: 'ぅ',
    le: 'ぇ',
    lo: 'ぉ',
};

const TOKENS = Object.keys(ROMAJI_TO_HIRAGANA).sort(
    (left, right) => right.length - left.length,
);

export function romajiToHiragana(value: string): string {
    const input = value.trim().toLowerCase();
    let hiragana = '';
    let index = 0;

    while (index < input.length) {
        const character = input[index];
        const nextCharacter = input[index + 1] ?? '';

        if (!/[a-z]/.test(character)) {
            hiragana += character;
            index++;

            continue;
        }

        if (
            character === nextCharacter &&
            !['a', 'e', 'i', 'n', 'o', 'u'].includes(character)
        ) {
            hiragana += 'っ';
            index++;

            continue;
        }

        if (character === 'n') {
            if (nextCharacter === "'") {
                hiragana += 'ん';
                index += 2;

                continue;
            }

            if (nextCharacter === 'n') {
                hiragana += 'ん';
                index += /[aeiouy]/.test(input[index + 2] ?? '') ? 1 : 2;

                continue;
            }

            if (nextCharacter === '' || !/[aeiouy]/.test(nextCharacter)) {
                hiragana += 'ん';
                index++;

                continue;
            }
        }

        const token = TOKENS.find((candidate) =>
            input.startsWith(candidate, index),
        );

        if (token) {
            hiragana += ROMAJI_TO_HIRAGANA[token];
            index += token.length;

            continue;
        }

        hiragana += character;
        index++;
    }

    return hiragana;
}
