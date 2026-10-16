# nhadep-static — Trình tạo trang tĩnh cho nhadepnghean.vn

> **Tóm tắt:** Lấy bài viết từ WordPress `noithatdatky.com` qua WP REST API → sinh **HTML tĩnh** (không cần database) → mỗi ngày một trang "tin tức ngày YYYY-MM-DD" → deploy lên Hostinger thay cho webroot Laravel hiện tại của **nhadepnghean.vn**.

---

## 1. Kiến trúc tổng quan

```
WordPress noithatdatky.com          máy Hostinger (nhadepnghean.vn)
┌──────────────────────┐            ┌─────────────────────────────────────┐
│  WP REST API         │   HTTPS    │  generate_nhadep_daily.php (cron    │
│  /wp-json/wp/v2/     │ ─────────▶ │  07:15 & 19:15 hàng ngày)           │
│  posts?_embed        │            │        │                            │
└──────────────────────┘            │        ▼                            │
                                    │  out/tin-tuc/YYYY-MM-DD/index.html  │
                                    │  out/index.html  (landing page)     │
                                    │  out/sitemap.xml, robots.txt, feed  │
                                    │        │                            │
                                    │        ▼                            │
                                    │  domains/nhadepnghean.vn/public_html│
                                    └─────────────────────────────────────┘
```

- **Không database.** Mọi trang là HTML tĩnh, CSS nhúng inline, JS tối thiểu (một snippet ~350 byte cho menu mobile + bóng topbar), webfont self-host (`assets/fonts/`, nhúng base64 trong generator) → tải cực nhanh, không request nào ra ngoài (bỏ Google Fonts CDN).
- Mỗi ngày có bài đăng mới → một trang digest `tin tức ngày <ngày>` liệt kê các bài đó (tiêu đề dẫn về bài gốc trên noithatdatky.com, mô tả, ảnh thumbnail lazy-load, giờ đăng gốc).
- Trang chủ `index.html` là landing page giới thiệu **Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An**, kèm lưới liên kết tới tất cả các trang theo ngày (mới nhất trước), meta SEO đầy đủ (canonical `https://nhadepnghean.vn/`, Open Graph, JSON-LD WebSite + ItemList).
- **JSON-LD LocalBusiness + BreadcrumbList:** nút `LocalBusiness` (tên, hotline, email, PostalAddress Diễn Châu — Nghệ An, logo, areaServed Nghệ An/Hà Tĩnh) có trên cả 3 loại trang (landing / trang ngày / lưu trữ), liên kết qua `@id` từ `WebSite.publisher` và `CollectionPage.publisher`; `BreadcrumbList` phủ đúng nav `.crumbs` nhìn thấy trên trang ngày (`Trang chủ › Tin tức › dd-mm-yyyy`) và lưu trữ (`Trang chủ › Lưu trữ`) — trang chủ không cần breadcrumb vì chính là cấp gốc.
- Idempotent: chạy lại chỉ sinh lại trang chủ/sitemap; các trang ngày đã có và không đổi dữ liệu nguồn sẽ được bỏ qua (hash lưu trong `out/.state.json`) trừ khi dùng `--force`. ⚠️ Vì hash chỉ gồm dữ liệu bài viết (không gồm HTML), lần triển khai markup mới (như JSON-LD, hay lần bỏ Google Fonts chuyển sang webfont self-host) cần chạy `--force` một lần để sinh lại toàn bộ trang ngày.

## 2. Yêu cầu

- PHP ≥ 8.0 (CLI), extension `curl` hoặc `allow_url_fopen`, `json`, `dom`/`mbstring` không bắt buộc.
- Không cần Composer, không thư viện ngoài.

## 3. Chạy local

```bash
cd nhadep-static/
php generate_nhadep_daily.php --out=out --max-posts=30   # chạy thật, lấy từ API
php -S localhost:8080 -t out                             # xem thử tại http://localhost:8080
```

> **Lưu ý Windows:** PHP CLI Windows thường thiếu CA bundle → lỗi `SSL certificate problem`. Tải
> [cacert.pem](https://curl.se/ca/cacert.pem) rồi chạy với
> `php -d curl.cainfo=<đường-dẫn>/cacert.pem -d openssl.cafile=<đường-dẫn>/cacert.pem generate_nhadep_daily.php ...`
> (không cần đụng php.ini toàn cục).

### 3.1. Chạy test

```bash
php tests/run_tests.php    # không cần thư viện ngoài; exit 0 = pass hết
```

Tổng số test hiện tại được in ra ở dòng `PASS: <n>/<n> tests passed`; README cố tình **không chép số cứng** để khỏi lệch mỗi khi thêm test.

Test phủ: escaping/URL allowlist, quy đổi giờ GMT→Việt Nam cho khóa ngày, sinh slug, **ghim slug cố định
cho từng ngày (stored thắng bài mới hơn, suffix khi trùng)**, nhãn ngày/giờ
(tiếng Việt), nhóm bài theo ngày, hash idempotency, **biên ghi file không thoát khỏi thư mục out**,
**render offline từ fixture cố định** (title/canonical/JSON-LD: CollectionPage, BlogPosting, LocalBusiness, BreadcrumbList; sitemap/feed well-formed),
state round-trip, CLI `--help`, và **quét/xóa có kiểm soát thư mục ngày mồ côi** (`--prune-orphans`, fail-closed khi state hỏng). Các bảo đảm mới từ S1–S3 cũng được phủ:

- **Feed trỏ trang ngày thật:** mỗi `<item>` trong `feed.xml` trỏ tới `<day-slug>/` của đúng trang ngày chứa bài,
  không trỏ slug nguồn mà generator không sinh (tránh link 404); khi thiếu map hợp lệ thì fallback về link nguồn đã allowlist, không bịa URL nhadepnghean.vn.
- **Phân trang fail-closed + retry bounded:** HTTP 400 kèm `rest_post_invalid_page_number` và mảng rỗng là kết thúc bình thường
  (không phải lỗi); lỗi khác được retry tối đa 3 lần (sleep 1s rồi 2s, test tắt sleep qua `NHADEP_RETRY_NO_SLEEP`);
  hết retry mà vẫn lỗi hoặc vượt `NHADEP_MAX_PAGES` (60) thì **dừng và báo lỗi** thay vì im lặng cắt dữ liệu.
- **Ảnh hero/og:image dùng biến thể `large`:** hero của trang ngày và `og:image`/`twitter:image` lấy ảnh `large`
  (fallback `full` → `medium_large` → `medium`), trong khi card danh sách vẫn dùng `medium`; kèm `og:image:width`/`og:image:height` khi biết kích thước.
- **Preload ảnh hero trang ngày (LCP):** trang ngày có ảnh hero phát sinh đúng một dòng
  `<link rel="preload" as="image" … fetchpriority="high">` trong `<head>` — URL đi qua cùng cơ chế escape/allowlist
  như `style="background-image…"` — để browser tải sớm ảnh LCP; trang không có hero ảnh thì không có preload nào.

## 4. Các tham số CLI của generator

| Cờ | Ý nghĩa |
|---|---|
| `--out=DIR` | Thư mục xuất kết quả (mặc định `./out`). Script **không bao giờ** ghi ra ngoài thư mục này. |
| `--date=YYYY-MM-DD` | Chỉ sinh lại đúng một ngày cụ thể. |
| `--force` | Sinh lại mọi trang kể cả khi dữ liệu nguồn không đổi. |
| `--max-posts=N` | Giới hạn số bài lấy về (mặc định: tất cả ~107 bài). |
| `--verbose` | In log chi tiết. |
| `--prune-orphans` | **Opt-in:** xóa các thư mục trang ngày "mồ côi" ở gốc `out/` (không nằm trong slug map của `.state.json`). Mặc định generator chỉ **cảnh báo (WARN)**, không xóa. |
| `--help` | Trợ giúp. |

Mã lỗi khác 0 khi có lỗi nghiêm trọng; log luôn ghi ra STDOUT.

## 5. Hợp đồng deploy (script phía người vận hành, **không có trong repo**)

`deploy.sh` là script do người vận hành tự giữ ở máy/workspace riêng — **KHÔNG nằm trong repo này**
(repo chỉ chứa `generate_nhadep_daily.php`, `tests/`, `.github/`, `README.md` và tarball mẫu; `tools/`/`out/` bị gitignore).
Mục này mô tả **hợp đồng hành vi** mà script đó phải thực hiện; đừng kỳ vọng `./deploy.sh` tồn tại sau khi clone.

```bash
SSH_HOST="<ip-hoặc-hostname>" ./deploy.sh              # an toàn: KHÔNG đụng public_html
DRY_RUN=1 SSH_HOST=... ./deploy.sh                     # chỉ IN hành động, không thực thi gì
CONFIRM_WIPE=1 SSH_HOST=... ./deploy.sh --cron         # deploy thật + cài cron
```

Biến môi trường: `SSH_HOST` (bắt buộc), `SSH_USER` (mặc định `u774297306`), `SSH_PORT` (mặc định 22), `SSH_KEY` (đường dẫn key, tùy chọn).

Các bước theo thứ tự:

1. **Preflight** — kiểm tra biến môi trường, sự tồn tại của `generate_nhadep_daily.php` và thư mục `out/` local.
2. **Upload generator** — scp `generate_nhadep_daily.php` lên `/home/u774297306/`, `chmod 0750`, kiểm tra `php -l` trên host.
3. **Backup** — trên host: `tar czf ~/backup_nhadep_public_html_$(date +%F).tar.gz` toàn bộ nội dung `domains/nhadepnghean.vn/public_html` **TRƯỚC bất kỳ thay đổi nào**. Ngày nào cũng tạo file backup riêng, không ghi đè backup cũ.
4. **Wipe gate** — nếu thiếu `CONFIRM_WIPE=1`, script **dừng tại đây** (exit 10) với hướng dẫn rõ ràng. Upload generator + backup ở trên vẫn đã hoàn thành an toàn.
5. **Xóa webroot cũ + cài file tĩnh** — chỉ khi `CONFIRM_WIPE=1`: xóa nội dung cũ (phạm vi giới hạn đúng thư mục public_html) rồi scp toàn bộ `out/.` vào đó.
6. **Cài cron** (với `--cron`) — dùng helper script upload lên host (kỹ thuật giống `../sync/deploy.sh`), dedupe bằng `grep -v 'generate_nhadep_daily\.php'` để chạy idempotent.

> ⚠️ **CẢNH BÁO QUAN TRỌNG:** Khi chạy với `CONFIRM_WIPE=1`, deploy sẽ **XÓA TOÀN BỘ file Laravel cũ** trong public_html của nhadepnghean.vn (sau khi đã backup tar.gz). Đây là hành động phá hủy — hãy chắc chắn bạn đã xem backup và hiểu rõ trước khi xác nhận.

### DRY_RUN

`DRY_RUN=1` in ra **mọi** lệnh sẽ chạy (scp, ssh, tar, rm, cron) nhưng **không thực thi bước phá hủy nào, không kết nối SSH nào**. Dùng để rà soát trước khi deploy thật:

```bash
DRY_RUN=1 CONFIRM_WIPE=1 SSH_HOST=... ./deploy.sh --cron   # xem cả lộ trình phá hủy trên giấy
```

## 6. Cron schedule

Hai dòng cron được đăng ký trên host (giờ theo đồng hồ server — server Hostinger thường dùng UTC, nên 07:15/19:15 server ≈ 14:15/02:15 giờ Việt Nam; ý định là **07:15 sáng + 19:15 tối theo Asia/Ho_Chi_Minh**, hãy kiểm tra `date` trên host nếu cần chỉnh):

```
15 7 * * *  /usr/bin/php /home/u774297306/generate_nhadep_daily.php >> /home/u774297306/generate_nhadep_daily.log 2>&1
15 19 * * * /usr/bin/php /home/u774297306/generate_nhadep_daily.php >> /home/u774297306/generate_nhadep_daily.log 2>&1
```

- **07:15**: lần chạy chính hằng ngày (sinh trang cho bài đăng mới).
- **19:15**: lần chạy dự phòng giữa buổi chiều/tối (catch-up cho bài đăng muộn).
- Generator idempotent nên chạy nhiều lần/ngày vô hại; log ghi cộng dồn vào `~/generate_nhadep_daily.log`.

## 7. Rollback (khôi phục)

Backup nằm ở `/home/u774297306/backup_nhadep_public_html_<YYYY-MM-DD>.tar.gz`. Khôi phục:

```bash
ssh u774297306@<host>
# 1. Dừng cron sinh trang (tránh ghi đè):
crontab -e          # xóa/tạm comment 2 dòng generate_nhadep_daily.php
# 2. Xóa nội dung tĩnh và giải nén backup:
rm -rf domains/nhadepnghean.vn/public_html/*
tar xzf ~/backup_nhadep_public_html_<YYYY-MM-DD>.tar.gz -C ~/domains/nhadepnghean.vn/public_html/
# 3. Kiểm tra website, sau đó bật lại cron nếu muốn.
```

## 8. Cấu trúc đầu ra (`out/`)

```
out/
├── index.html                  ← landing page (luôn được sinh lại)
├── sitemap.xml                 ← cập nhật mỗi lần chạy
├── robots.txt                  ← allow all + Sitemap ref
├── feed.xml                    ← RSS 20 mục mới nhất (nice-to-have)
├── .state.json                 ← hash từng ngày (idempotency) + map slugs đã gán (ổn định URL)
├── assets/
│   └── fonts/                  ← 16 file woff2 self-host (2 family × 8 face × 2 subset
│                                 vietnamese+latin), ghi lại mỗi lần chạy (idempotent)
├── tin-tuc/
│   └── index.html              ← hub lưu trữ: lưới tất cả các trang, mới nhất trước
├── <slug-chủ-đề>/
│   └── index.html              ← trang "tin tức ngày YYYY-MM-DD" của một ngày;
│                                 slug lấy từ bài mới nhất NGAY LẦN ĐẦU sinh trang ngày đó,
│                                 nằm ở gốc (ví dụ: /thi-cong-noi-that-chung-cu-do-luong/)
└── ...
```

> Lược đồ URL **slug theo chủ đề** (commit `217723c`): mỗi ngày một thư mục slug ở gốc `out/`,
> hub lưu trữ tại `tin-tuc/`. Các lược đồ cũ `tin-tuc/YYYY-MM-DD/` (trong tarball mẫu) không còn dùng.
>
> **Ổn định URL** (2026-10-15): slug mỗi ngày được gán đúng một lần rồi ghi vào
> `.state.json` (`slugs`), các lần chạy sau tái sử dụng nguyên giá trị — bài mới hơn đăng
> trong ngày **không** làm đổi URL trang ngày; slug đã gán không bao giờ bị ngày khác tái sử dụng
> (ngày mới trùng slug tự nhận hậu tố `-2`, `-3`…).

> **Thư mục mồ côi (NHADEP-F2-ORPHANS):** nếu `.state.json` bị mất/reset trên host (hoặc slug đổi),
> các dir slug cũ ở gốc `out/` không còn thuộc slug map sẽ thành mồ côi — 404/rác/trùng lặp nội dung
> khi deploy lại. Sau mỗi lần chạy, generator quét gốc `out/` và **WARN** số lượng + tên từng dir mồ côi
> (exit code vẫn 0, không làm hỏng cron). Muốn xóa, chạy lại với `--prune-orphans`: mỗi dir bị xóa được
> log kèm tổng số. Xóa chỉ diễn ra khi (a) cờ tường minh, (b) dir ngoài tập giữ lại (slug + `tin-tuc` +
> `assets`), (c) dir nằm **nghiêm ngặt trong** `out/` đã realpath (chặn `..`/symlink ra ngoài). Nếu
> `.state.json` không đọc/parse được → **không xóa gì**, exit ≠ 0 (fail-closed).

Mỗi trang khoảng 40–65KB HTML thô tuỳ lượng bài/ngày (~9–14KB khi nén gzip), UTF-8 tiếng Việt,
CSS inline mobile-first, không phụ thuộc framework. **Webfont self-host** (NHADEP-F1-FONTS):
`Cormorant Garamond` (600/700/600-italic) + `Be Vietnam Pro` (300–700) — subset `vietnamese` +
`latin`, payload base64 nhúng trong `nhadep_font_assets()`, được ghi ra `assets/fonts/` đầu mỗi
lần chạy và khai báo qua `@font-face` (kèm `unicode-range` + `font-display:swap`) ở đầu `<style>`.
Trang không còn request font ra ngoài; font là file tĩnh phục vụ cùng origin nên trang vẫn hiển
thị đúng typography kể cả khi mất mạng.

### 8.1. Design system (redesign "Architectural Editorial")

Toàn bộ giao diện nằm trong `nhadep_css()` (nguồn duy nhất) rồi được **minify lúc emit** (bỏ comment + xuống dòng)
trước khi nhúng vào `<style>` của từng trang:

- **Tokens**: bảng màu ấm (nền `--paper` #FCFAF7, mực `--ink` #1B1611, nhấn đồng thau `--accent` #9A6B2F,
  vàng trang trí `--gold`), thang chữ Cormorant Garamond (display) + Be Vietnam Pro (nội dung), radius/shadow/ease dùng biến chung.
- **Components**: topbar glass dính (brand mark `DK`, nav gạch chân động, nút hotline), hero ảnh lớn + scrim chuyển sắc,
  `sec-head` trái với kicker + link "Xem tất cả", card tin 16:11 (chip + date badge + CTA mũi tên),
  lead card trang ngày (số thứ tự 01/02…), day-cell Ngày/Tháng, tháng lưu trữ, contact card tối, footer 3 cột.
- **Responsive**: 1 cột → 2 cột (≥600px) → 3 cột (≥900px); menu mobile bằng checkbox thuần CSS (`#nav-open`)
  kèm snippet đóng menu khi bấm link; tôn trọng `prefers-reduced-motion`; có skip-link, `:focus-visible`, favicon SVG inline, `theme-color`.
- Snippet JS ở footer cũng bật shadow cho topbar khi cuộn (`scrollY > 8`); không có JS thì trang vẫn hoạt động bình thường.
