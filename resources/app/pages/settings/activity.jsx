import { ago, dateTime, Page } from '../../components/ui.jsx';
import { appUrl, route } from '../../router.jsx';

export default function Activity({ logs }) {
  return (
    <Page heading="Settings">
      <s-section heading="Activity">
        {!logs.data.length ? <s-paragraph>No activity yet.</s-paragraph> : (
          <>
            <div className="oo-scroll">
              <table className="oo-table stack">
                <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>Request ID</th></tr></thead>
                <tbody>
                  {logs.data.map((log) => (
                    <tr key={log.id}>
                      <td title={dateTime(log.created_at)}>{ago(log.created_at)}</td>
                      <td data-label="Who">{log.who}</td>
                      <td>{log.what}</td>
                      <td className="oo-muted oo-small">{log.details}</td>
                      <td>{log.request_id && <span className="oo-code">{log.request_id}</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <s-stack direction="inline" gap="small-200" paddingBlockStart="base">
              {logs.page > 1 && <s-button href={appUrl(route('app.settings.activity', { page: logs.page - 1 }))}>Newer</s-button>}
              {logs.page < logs.last_page && <s-button href={appUrl(route('app.settings.activity', { page: logs.page + 1 }))}>Older</s-button>}
            </s-stack>
          </>
        )}
      </s-section>
    </Page>
  );
}
