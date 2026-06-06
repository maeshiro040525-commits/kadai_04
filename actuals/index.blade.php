@extends('layouts.app')

@section('title', '実績確認（MVP）')

@section('content')
{{-- 実績確認画面: 月次実績を加算単位で表示する。 --}}
<h1>実績確認（MVP）</h1>

@if (session('status'))
  <div class="box">{{ session('status') }}</div>
@endif

<div class="box">
  {{-- 絞り込みフォーム --}}
  <form method="GET" action="{{ route('evidence.actuals.index') }}">
    <div class="row">
      <div>
        <label>施設</label>
        <select name="facility_id">
          @foreach($facilities as $f)
            <option value="{{ $f->id }}" @selected((string)$facilityId === (string)$f->id)>
              {{ $f->name }}（id: {{ $f->id }}）
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label>年度（YYYY）</label>
        <input type="text" name="fiscal_year" value="{{ $fiscalYear }}" placeholder="2026">
      </div>

      <div style="align-self:end;">
        <button type="submit">表示</button>
      </div>

      <div style="align-self:end;">
        <a href="{{ route('evidence.actuals.input.index', ['facility_id' => $facilityId, 'fiscal_year' => $fiscalYear]) }}">実績入力へ</a>
      </div>
    </div>
  </form>
</div>

@if(!$facility)
  {{-- 施設未登録時 --}}
  <div class="box">施設がありません。先に facilities を1件作ってください。</div>
@else
  <div class="box">
    {{-- 実績テーブル（確認専用） --}}
    <p class="muted">
      行：各種加算（基本分＋区分1・2を合算表示） / 列：4月〜3月 / この画面は確認専用です。
    </p>

    <table border="1" cellpadding="6" cellspacing="0">
      <thead>
        <tr>
          <th>各種加算</th>
          @foreach($months as $ym)
            <th>{{ $ym }}</th>
          @endforeach
          <th>年度合計</th>
        </tr>
      </thead>
      <tbody>
        @foreach($codes as $c)
          @php $code = $c['code']; @endphp
          <tr>
            <td>{{ $c['name'] }}</td>

            @foreach($months as $ym)
              <td style="text-align:right;">
                {{ number_format((int)($values[$code][$ym] ?? 0)) }}
              </td>
            @endforeach

            <td style="text-align:right;">
              {{ number_format($rowTotals[$code] ?? 0) }}
            </td>
          </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <th colspan="{{ 1 + count($months) }}">総合計</th>
          <th style="text-align:right;">{{ number_format($grandTotal) }}</th>
        </tr>
      </tfoot>
    </table>
  </div>
@endif

@endsection

