<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pengaduan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PengaduanAdminController extends Controller
{
    private const KATEGORI = [
        'Pelayanan',
        'Infrastruktur',
        'Keamanan',
        'Bansos',
        'Lingkungan',
        'Lainnya',
    ];

    private const STATUS = ['menunggu', 'diproses', 'selesai', 'ditolak'];

    public function index(Request $request)
    {
        $filter = $request->validate([
            'kategori' => ['nullable', 'string', Rule::in(self::KATEGORI)],
            'status' => ['nullable', 'string', Rule::in(self::STATUS)],
        ], [
            'kategori.in' => 'Kategori pengaduan tidak valid.',
            'status.in' => 'Status pengaduan tidak valid.',
        ]);

        $pengaduans = Pengaduan::query()
            ->with('warga')
            ->when($filter['kategori'] ?? null, fn ($query, $kategori) => $query->where('kategori', $kategori))
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.pengaduan.index', compact('pengaduans'));
    }

    public function show($id)
    {
        $pengaduan = Pengaduan::with(['warga', 'ditanggapiOleh'])->findOrFail($id);

        return view('admin.pengaduan.tanggapi', compact('pengaduan'));
    }

    public function tanggapi(Request $request, $id): RedirectResponse
    {
        $this->pastikanAdmin($request);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(self::STATUS)],
            'tanggapan' => ['nullable', 'required_without:status', 'string', 'max:10000'],
        ], [
            'status.required' => 'Status pengaduan wajib dipilih.',
            'status.in' => 'Status pengaduan tidak valid.',
            'tanggapan.required_without' => 'Tanggapan wajib diisi.',
            'tanggapan.max' => 'Tanggapan maksimal 10.000 karakter.',
        ]);

        $pengaduan = Pengaduan::findOrFail($id);
        $perubahan = [];

        if (array_key_exists('status', $data)) {
            $perubahan['status'] = $data['status'];
        }

        if (array_key_exists('tanggapan', $data)) {
            // Normalisasi CRLF ke LF agar perbandingan tidak menganggap tanggapan lama berubah saat hanya status yang diperbarui.
            $tanggapanBaru = str_replace("\r\n", "\n", trim((string) $data['tanggapan']));
            $tanggapanLama = str_replace("\r\n", "\n", trim((string) $pengaduan->tanggapan));

            if ($tanggapanBaru === '') {
                if ($pengaduan->tanggapan !== null) {
                    $perubahan['tanggapan'] = null;
                    $perubahan['ditanggapi_oleh'] = null;
                    $perubahan['tanggal_tanggapan'] = null;
                }
            } elseif ($tanggapanBaru !== $tanggapanLama) {
                $perubahan['tanggapan'] = $data['tanggapan'];
                $perubahan['ditanggapi_oleh'] = $request->user()->id;
                $perubahan['tanggal_tanggapan'] = now();
            }
        }

        if ($perubahan !== []) {
            $pengaduan->update($perubahan);
        }

        $pesanSukses = match (true) {
            isset($perubahan['status'], $perubahan['tanggapan']) => 'Status dan tanggapan pengaduan berhasil disimpan.',
            isset($perubahan['tanggapan']) => 'Tanggapan pengaduan berhasil disimpan.',
            isset($perubahan['status']) => 'Status pengaduan berhasil diperbarui.',
            default => 'Perubahan pengaduan berhasil disimpan.',
        };

        return back()->with('sukses', $pesanSukses);
    }

    public function updateStatus(Request $request, $id): RedirectResponse
    {
        $this->pastikanAdmin($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUS)],
        ], [
            'status.required' => 'Status pengaduan wajib dipilih.',
            'status.in' => 'Status pengaduan tidak valid.',
        ]);

        $pengaduan = Pengaduan::findOrFail($id);
        $pengaduan->update(['status' => $data['status']]);

        return back()->with('sukses', 'Status pengaduan berhasil diperbarui.');
    }

    private function pastikanAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin(),
            403,
            'Akses pengelolaan pengaduan hanya tersedia untuk admin dan perangkat desa.'
        );
    }
}
