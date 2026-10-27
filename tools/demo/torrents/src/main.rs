// See Cargo.toml.
use torrentds::TorrentMeta;
use torrentds::store::Store;

const GB: u64 = 1 << 30;
const MB: u64 = 1 << 20;

fn ih(name: &str) -> [u8; 20] {
    torrentds::sha1(name.as_bytes())
}

fn main() {
    let db = std::env::args().nth(1).expect("db path");
    let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_secs();
    let t: Vec<(&str, Vec<(&str, u64)>, u64)> = vec![
        ("debian-13.1.0-amd64-netinst.iso", vec![("debian-13.1.0-amd64-netinst.iso", 754 * MB)], 2),
        ("debian-live-13.1.0-amd64-xfce.iso", vec![("debian-live-13.1.0-amd64-xfce.iso", 3 * GB + 210 * MB)], 3),
        ("ubuntu-26.04-desktop-amd64.iso", vec![("ubuntu-26.04-desktop-amd64.iso", 6 * GB + 40 * MB)], 5),
        ("tails-amd64-7.1.img", vec![("tails-amd64-7.1.img", 1 * GB + 580 * MB)], 1),
        ("archlinux-2026.10.01-x86_64.iso", vec![("archlinux-2026.10.01-x86_64.iso", 1 * GB + 220 * MB)], 4),
        ("Fedora-Workstation-Live-43-1.6.x86_64.iso", vec![("Fedora-Workstation-Live-43-1.6.x86_64.iso", 2 * GB + 600 * MB)], 9),
        ("NixOS 26.05 minimal ISO", vec![("nixos-minimal-26.05-x86_64-linux.iso", 1 * GB + 120 * MB), ("nixos-minimal-26.05-x86_64-linux.iso.sha256", 98)], 6),
        ("Big Buck Bunny (2008) 1080p", vec![("Big Buck Bunny/bbb_sunflower_1080p_60fps_normal.mp4", 355 * MB), ("Big Buck Bunny/poster.jpg", 2 * MB), ("Big Buck Bunny/README.txt", 1200)], 30),
        ("Sintel (2010) 4K", vec![("Sintel/sintel-4k.mkv", 4 * GB + 300 * MB), ("Sintel/subtitles/sintel_en.srt", 9000), ("Sintel/subtitles/sintel_it.srt", 9400)], 40),
        ("Tears of Steel (2012) 1080p", vec![("Tears of Steel/tears_of_steel_1080p.mov", 584 * MB), ("Tears of Steel/tos_en.srt", 4800)], 22),
        ("Night of the Living Dead (1968) public domain restoration", vec![("Night.of.the.Living.Dead.1968.1080p.mkv", 7 * GB + 100 * MB)], 60),
        ("Nosferatu (1922) restored, public domain", vec![("Nosferatu.1922.720p.mkv", 2 * GB + 900 * MB), ("Nosferatu.1922.en.srt", 41000)], 70),
        ("Project Gutenberg - Top 1000 ebooks (EPUB)", vec![("gutenberg/pride-and-prejudice.epub", 600_000), ("gutenberg/frankenstein.epub", 450_000), ("gutenberg/moby-dick.epub", 1_200_000), ("gutenberg/alice-in-wonderland.epub", 300_000)], 15),
        ("wikipedia_en_all_maxi_2026-09.zim", vec![("wikipedia_en_all_maxi_2026-09.zim", 109 * GB)], 8),
        ("wikipedia_it_all_nopic_2026-09.zim", vec![("wikipedia_it_all_nopic_2026-09.zim", 14 * GB)], 11),
        ("OpenStreetMap planet-261005.osm.pbf", vec![("planet-261005.osm.pbf", 81 * GB)], 12),
        ("Kevin MacLeod - Royalty Free Music Collection (CC BY)", vec![("incompetech/Sneaky Snitch.mp3", 4 * MB), ("incompetech/Monkeys Spinning Monkeys.mp3", 5 * MB), ("incompetech/Fluffing a Duck.mp3", 3 * MB), ("incompetech/LICENSE.txt", 2000)], 45),
        ("NASA Apollo 11 - Hasselblad photographs (full resolution)", vec![("apollo11/AS11-40-5903.tif", 160 * MB), ("apollo11/AS11-40-5875.tif", 158 * MB), ("apollo11/AS11-44-6552.tif", 150 * MB)], 25),
        ("Prelinger Archives - Duck and Cover (1951)", vec![("DuckandC1951.mp4", 210 * MB)], 90),
        ("Blender 4.5 LTS - Linux x64", vec![("blender-4.5.3-linux-x64.tar.xz", 340 * MB)], 7),
        ("LibreOffice 26.2 - Linux x86_64 deb", vec![("LibreOffice_26.2.1_Linux_x86-64_deb.tar.gz", 230 * MB)], 10),
        ("Tor Browser 15.0 - Linux x86_64", vec![("tor-browser-linux-x86_64-15.0.tar.xz", 110 * MB), ("tor-browser-linux-x86_64-15.0.tar.xz.asc", 833)], 3),
        ("Common Crawl sample - CC-MAIN-2026-40 WET subset", vec![("CC-MAIN-2026-40/wet-00000.warc.wet.gz", 140 * MB), ("CC-MAIN-2026-40/wet-00001.warc.wet.gz", 139 * MB)], 20),
        ("MIT OpenCourseWare 6.006 Introduction to Algorithms (CC BY-NC-SA)", vec![("6.006/lecture-01.mp4", 300 * MB), ("6.006/lecture-02.mp4", 310 * MB), ("6.006/notes.pdf", 8 * MB)], 33),
    ];
    let mut store = Store::new();
    for (i, (name, files, age_days)) in t.iter().enumerate() {
        let total: u64 = files.iter().map(|f| f.1).sum();
        let pl = if total > 4 * GB { 16 * MB } else { 2 * MB };
        let meta = TorrentMeta {
            info_hash: ih(name),
            name: name.to_string(),
            total_size: total,
            piece_length: pl,
            piece_count: ((total + pl - 1) / pl) as usize,
            files: files.iter().map(|(p, s)| (p.to_string(), *s)).collect(),
            info_bytes: None,
            info_hash_v2: None,
            version: "v1",
            content_id: Some(torrentds::sha256(name.as_bytes())),
        };
        let seen = now - age_days * 86_400 - (i as u64) * 3_700;
        store.add_discovered(&meta.info_hash, None, seen);
        for k in 0..(1 + (i % 4)) {
            store.store_metadata(&meta, seen + k as u64 * 40_000);
            store.mark_fetched(&meta.info_hash);
        }
    }
    std::fs::write(&db, store.snapshot()).unwrap();
    println!("{} torrents", store.len());
}
