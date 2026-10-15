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

- **Không database.** Mọi trang là HTML tĩnh, CSS nhúng inline, không tài nguyên ngoài → tải cực nhanh.
- Mỗi ngày có bài đăng mới → một trang digest `tin tức ngày <ngày>` liệt kê các bài đó (tiêu đề dẫn về bài gốc trên noithatdatky.com, mô tả, ảnh thumbnail lazy-load, giờ đăng gốc).
- Trang chủ `index.html` là landing page giới thiệu **Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An**, kèm lưới liên kết tới tất cả các trang theo ngày (mới nhất trước), meta SEO đầy đủ (canonical `https://nhadepnghean.vn/`, Open Graph, JSON-LD WebSite + ItemList).
- Idempotent: chạy lại chỉ sinh lại trang chủ/sitemap; các trang ngày đã có và không đổi dữ liệu nguồn sẽ được bỏ qua (hash lưu trong `out/.state.json`) trừ khi dùng `--force`.

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
php tests/run_tests.php    # 64 test, không cần thư viện ngoài; exit 0 = pass hết
```

Test phủ: escaping/URL allowlist, quy đổi giờ GMT→Việt Nam cho khóa ngày, sinh slug, nhãn ngày/giờ
(tiếng Việt), nhóm bài theo ngày, hash idempotency, **biên ghi file không thoát khỏi thư mục out**,
**render offline từ fixture cố định** (title/canonical/JSON-LD; sitemap/feed well-formed),
state round-trip, và CLI `--help`.

## 4. Các tham số CLI của generator

| Cờ | Ý nghĩa |
|---|---|
| `--out=DIR` | Thư mục xuất kết quả (mặc định `./out`). Script **không bao giờ** ghi ra ngoài thư mục này. |
| `--date=YYYY-MM-DD` | Chỉ sinh lại đúng một ngày cụ thể. |
| `--force` | Sinh lại mọi trang kể cả khi dữ liệu nguồn không đổi. |
| `--max-posts=N` | Giới hạn số bài lấy về (mặc định: tất cả ~107 bài). |
| `--verbose` | In log chi tiết. |
| `--help` | Trợ giúp. |

Mã lỗi khác 0 khi có lỗi nghiêm trọng; log luôn ghi ra STDOUT.

## 5. deploy.sh hoạt động thế nào?

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
├── .state.json                 ← hash dữ liệu từng ngày (idempotency)
├── tin-tuc/
│   └── index.html              ← hub lưu trữ: lưới tất cả các trang, mới nhất trước
├── <slug-chủ-đề>/
│   └── index.html              ← trang "tin tức ngày YYYY-MM-DD" của một ngày;
│                                 slug lấy từ bài mới nhất của ngày đó, nằm ở gốc
│                                 (ví dụ: /thi-cong-noi-that-chung-cu-do-luong/)
└── ...
```

> Lược đồ URL **slug theo chủ đề** (commit `217723c`): mỗi ngày một thư mục slug ở gốc `out/`,
> hub lưu trữ tại `tin-tuc/`. Các lược đồ cũ `tin-tuc/YYYY-MM-DD/` (trong tarball mẫu) không còn dùng.

Mỗi trang < 50KB (chưa tính ảnh), UTF-8 tiếng Việt, CSS inline mobile-first, hoàn toàn standalone.
