import Svg, { Path, Circle, Rect } from 'react-native-svg';
import type { ColorValue } from 'react-native';
import { colors } from '@/theme/tokens';

export type IconName = 'home' | 'search' | 'heart' | 'calendar' | 'user' | 'pin' | 'arrow' | 'star' | 'leaf' | 'alert' | 'photo' | 'clock';
export function Icon({ name, size = 22, color = colors.green800 }: { name: IconName; size?: number; color?: ColorValue }) {
  const paths: Partial<Record<IconName, string>> = {
    home: 'M3 10 12 3l9 7v11h-6v-7H9v7H3Z', heart: 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z',
    pin: 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0ZM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
    arrow: 'M5 12h14m-6-6 6 6-6 6', star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z',
    leaf: 'M20 3C8 2 2 8 5 16s18 3 15-13ZM5 20 16 9', alert: 'M12 3 2 21h20ZM12 9v5m0 3v1',
    photo: 'M3 17 8 12l5 5 3-3 5 5',
  };
  return <Svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" accessibilityElementsHidden>
    {paths[name] && <Path d={paths[name]} />}
    {name === 'search' && <><Circle cx={10.5} cy={10.5} r={7} /><Path d="m16 16 5 5" /></>}
    {name === 'calendar' && <><Rect x={3} y={5} width={18} height={16} rx={3} /><Path d="M7 3v4m10-4v4M3 11h18m-13 5h2m4 0h2" /></>}
    {name === 'user' && <><Circle cx={12} cy={8} r={4} /><Path d="M4 21v-2a8 8 0 0 1 16 0v2" /></>}
    {name === 'photo' && <><Rect x={2} y={3} width={20} height={18} rx={3} /><Circle cx={16} cy={8} r={2} /></>}
    {name === 'clock' && <><Circle cx={12} cy={12} r={9} /><Path d="M12 7v5l3 2" /></>}
  </Svg>;
}
